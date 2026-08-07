<?php
/**
 * Schema-drift guard  (ARMED in CI 2026-07-23 — static mode; live checks
 * need DB env vars and remain a manual/staging step).
 *
 * Detects schema drift between three sources of truth:
 *
 *   (1) DECLARED   — the `CREATE TABLE` statements in the bcc-* plugins'
 *                    schema files (includes/database/schema-*.php and any
 *                    dbDelta() caller), plus the TableRegistry::all()
 *                    accessor list as a belt-and-suspenders declared set.
 *   (2) DOCUMENTED — the master-inventory table in docs/database-schema.md
 *                    (regenerated from live on 2026-06-25).
 *   (3) LIVE       — the actual MySQL database (optional; skipped cleanly
 *                    when no DB is reachable).
 *
 * The high-value drift this catches:
 *   - tables/indexes that exist LIVE but were never DECLARED. WordPress
 *     dbDelta ADDS but never DROPS, so live accumulates duplicate/legacy
 *     indexes (e.g. wp_bcc_trust_votes carries both idx_voter_history and
 *     an exact-duplicate idx_voter_created over (voter_user_id,created_at));
 *   - tables added in code but never written into the doc;
 *   - documented-Active tables missing from live.
 *
 * ── PHASE STATUS ──────────────────────────────────────────────────────
 * ARMED AND BLOCKING. Phase 5 reconciled live == declared == documented
 * (Tier-3 sweep, 2026-07-23: parser fixes + drop-legacy-indexes v2 +
 * database-schema.md regen), and this guard was wired into the umbrella
 * `.github/workflows/ci.yml` on the same day as
 * "Schema-drift parity (static)" — `run: php scripts/schema-drift-guard.php`
 * with NO `continue-on-error`, so a non-zero exit fails the build.
 *
 * It is therefore NOT informational: any drift it reports is NEW drift.
 * In particular, adding a table in code without adding its row to
 * docs/database-schema.md turns CI red (CHECK 1b).
 *
 * The historical note this block used to carry — that the guard was
 * expected to report the known duplicate indexes and the 17 orphan
 * tables — describes pre-Phase-5 state and no longer applies; those were
 * dropped, and the guard reported zero findings on a clean tree at arming
 * time.
 *
 * CI runs STATIC MODE ONLY (no DB env vars), so the live-table and
 * live-index checks below self-skip. The live half remains a manual /
 * staging step.
 *
 * ── HOW TO RUN ────────────────────────────────────────────────────────
 *   # Static-only (DECLARED vs DOCUMENTED — no DB needed):
 *   php scripts/schema-drift-guard.php
 *
 *   # With the live DB (adds live-table + live-index drift checks).
 *   # Connection params come from env first, then local-site.json:
 *   BCC_DB_HOST=127.0.0.1 BCC_DB_PORT=10005 \
 *   BCC_DB_USER=root BCC_DB_PASS=root BCC_DB_NAME=local \
 *     php scripts/schema-drift-guard.php
 *
 * ── EXIT CODES ────────────────────────────────────────────────────────
 *   0 = no drift detected
 *   1 = drift detected (static and/or live)
 *   2 = setup / IO error (missing required input file, bad repo root)
 *
 * INFO-class observations (documented-ORPHAN tables still live, etc.) are
 * printed but do NOT by themselves set a non-zero exit — they are expected
 * pre-Phase-5 state.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from CLI.\n");
    exit(2);
}

$REPO_ROOT = realpath(__DIR__ . '/..');
if ($REPO_ROOT === false) {
    fwrite(STDERR, "Cannot resolve repo root.\n");
    exit(2);
}

$PLUGINS_DIR  = $REPO_ROOT . '/app/public/wp-content/plugins';
$SCHEMA_DOC   = $REPO_ROOT . '/docs/database-schema.md';
$TABLE_REG    = $PLUGINS_DIR . '/bcc-trust/app/Domain/Core/Database/TableRegistry.php';
$LOCAL_SITE   = $REPO_ROOT . '/local-site.json';

foreach ([$SCHEMA_DOC => 'docs/database-schema.md', $PLUGINS_DIR => 'plugins dir'] as $path => $label) {
    if (!file_exists($path)) {
        fwrite(STDERR, "Missing required input ({$label}): {$path}\n");
        exit(2);
    }
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Normalize any full or partial table name to its bare `bcc_...` form,
 * dropping a leading WP prefix (`wp_`, `wp_2_`, etc.). Comparisons across
 * the three sources all happen in this bare space.
 */
function bare_name(string $name): string {
    $name = trim($name, " `'\"");
    // Strip a leading WP-style prefix up to and including the 'bcc_' marker.
    if (preg_match('/(bcc_[a-z0-9_]+)$/i', $name, $m)) {
        return strtolower($m[1]);
    }
    return strtolower($name);
}

/** Normalize a comma column list into a canonical "a,b,c" string. */
function canon_cols(string $cols): string {
    $parts = array_map(
        static fn(string $c): string => strtolower(trim($c, " `\t\r\n")),
        explode(',', $cols)
    );
    // Strip index prefix-length annotations like `endpoint(191)`.
    $parts = array_map(static fn(string $c): string => preg_replace('/\(\d+\)$/', '', $c) ?? $c, $parts);
    $parts = array_filter($parts, static fn(string $c): bool => $c !== '');
    return implode(',', $parts);
}

/**
 * @return list<string> php files under $dir, excluding vendor/ & node_modules/
 */
function php_files(string $dir): array {
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    $it  = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        $path = $file->getPathname();
        $norm = str_replace('\\', '/', $path);
        if (strpos($norm, '/vendor/') !== false || strpos($norm, '/node_modules/') !== false) {
            continue;
        }
        if (substr($path, -4) === '.php') {
            $out[] = $path;
        }
    }
    return $out;
}

/*
|--------------------------------------------------------------------------
| (1) DECLARED — parse CREATE TABLE statements out of the bcc-* plugins
|--------------------------------------------------------------------------
| Resolution model (pragmatic but correct for this codebase's idioms):
|
|   A global symbol table maps name-producing expressions to bare names:
|     - DB::table('X')                  => bcc_X            (per bcc-core DB.php)
|     - TableRegistry::method()         => bare literal from method body
|     - helper/static fn returning one of the above or `$wpdb->prefix.'lit'`
|
|   Then, for each CREATE TABLE, the variable that names the table is traced
|   to its assignment in the SAME file and resolved via that symbol table,
|   handling `$other . '_suffix'` concatenation recursively, plus the inline
|   `$wpdb->prefix . 'bcc_lit'` and direct `DB::table('x')` / `Class::m()`
|   forms.
*/

/** Only scan bcc-* plugin dirs for DECLARED (never peepso, never vendor). */
$bccPluginDirs = array_filter(
    glob($PLUGINS_DIR . '/bcc-*', GLOB_ONLYDIR) ?: [],
    'is_dir'
);

$declaredFiles = [];
foreach ($bccPluginDirs as $pdir) {
    foreach (php_files($pdir) as $f) {
        $declaredFiles[] = $f;
    }
}

/**
 * Build the global symbol table: function/static-method name => bare table.
 *
 * @return array{funcs: array<string,string>, methods: array<string,string>, ambiguous: array<string,bool>}
 */
function build_symbol_table(array $files): array {
    $funcs   = []; // bcc_*_table()  => bare
    $methods = []; // methodname (lc) => bare
    $seen    = []; // methodname (lc) => set of distinct bare values (ambiguity tracking)

    foreach ($files as $file) {
        foreach (file_methods($file) as $mname => $bare) {
            $methods[$mname] = $bare;
            $seen[$mname][$bare] = true;
        }
        foreach (file_functions($file) as $fname => $bare) {
            $funcs[$fname] = $bare;
        }
    }

    $ambiguous = [];
    foreach ($seen as $mname => $values) {
        $ambiguous[$mname] = count($values) > 1;
    }

    return ['funcs' => $funcs, 'methods' => $methods, 'ambiguous' => $ambiguous];
}

/**
 * Static no-arg `: string` methods defined in a file, mapped to bare table.
 * @return array<string,string>  lowercase method name => bare
 */
function file_methods(string $file): array {
    $src = (string) file_get_contents($file);
    $out = [];
    // Same-file string consts (`private const TABLE_SUFFIX = 'bcc_x';`) so
    // `return $wpdb->prefix . self::TABLE_SUFFIX;` accessors resolve. Without
    // this, such a table() falls through to the AMBIGUOUS global map and can
    // be attributed to a different repository's table entirely (the exact
    // failure that credited bcc_search_terms' CREATE TABLE — and its
    // indexes — to wp_bcc_trust_votes).
    $consts = file_string_consts($src);
    if (preg_match_all(
        '/(?:public|protected|private)\s+static\s+function\s+([a-z0-9_]+)\s*\([^)]*\)\s*:\s*\??\s*string\s*\{(.*?)\}/is',
        $src,
        $mm,
        PREG_SET_ORDER
    )) {
        foreach ($mm as $m) {
            $bare = resolve_return_to_bare($m[2], $consts);
            if ($bare !== null) {
                $out[strtolower($m[1])] = $bare;
            }
        }
    }
    return $out;
}

/**
 * `const NAME = 'string';` declarations in a source string.
 * @return array<string,string>  const name => literal value
 */
function file_string_consts(string $src): array {
    $out = [];
    if (preg_match_all(
        '/const\s+([A-Z0-9_]+)\s*=\s*[\'"]([a-z0-9_]+)[\'"]\s*;/',
        $src,
        $cm,
        PREG_SET_ORDER
    )) {
        foreach ($cm as $c) {
            $out[$c[1]] = $c[2];
        }
    }
    return $out;
}

/**
 * Standalone helper functions in a file, mapped to bare table.
 * @return array<string,string>  function name => bare
 */
function file_functions(string $file): array {
    $src = (string) file_get_contents($file);
    $out = [];
    if (preg_match_all(
        '/function\s+([a-z0-9_]+)\s*\([^)]*\)\s*(?::\s*\??\s*string\s*)?\{(.*?)\}/is',
        $src,
        $fm,
        PREG_SET_ORDER
    )) {
        foreach ($fm as $f) {
            $bare = resolve_return_to_bare($f[2]);
            if ($bare !== null) {
                $out[$f[1]] = $bare;
            }
        }
    }
    return $out;
}

/**
 * Resolve a function/method body (its `return ...;`) to a bare table name,
 * or null if it isn't a simple table-name accessor.
 *
 * @param array<string,string> $consts same-file `const NAME = 'literal'` map,
 *                                     so `prefix . self::TABLE_SUFFIX` resolves.
 */
function resolve_return_to_bare(string $body, array $consts = []): ?string {
    if (!preg_match('/return\s+(.+?);/is', $body, $r)) {
        return null;
    }
    $expr = trim($r[1]);

    // return \BCC\Core\DB\DB::table('x');   ->  bcc_x
    if (preg_match('/DB::table\(\s*[\'"]([a-z0-9_]+)[\'"]\s*\)/i', $expr, $m)) {
        return 'bcc_' . strtolower($m[1]);
    }
    // return $wpdb->prefix . 'bcc_literal';
    if (preg_match('/prefix\s*\.\s*[\'"]([a-z0-9_]+)[\'"]/i', $expr, $m)) {
        return bare_name($m[1]);
    }
    // return $wpdb->prefix . self::TABLE_SUFFIX;  (const declared in-file)
    if (preg_match('/prefix\s*\.\s*(?:self|static)::([A-Z0-9_]+)/', $expr, $m)
        && isset($consts[$m[1]])) {
        return bare_name($consts[$m[1]]);
    }
    return null;
}

$SYMBOLS = build_symbol_table($declaredFiles);

/**
 * Resolve a single PHP expression that yields a table name to a bare name.
 * Used both for direct CREATE-TABLE expressions and for variable assignments.
 */
function resolve_expr_to_bare(string $expr, array $symbols, array $varMap, array $localMethods = []): ?string {
    $expr = trim($expr);

    // Concatenation with a string suffix:  $other . '_velocity'
    if (preg_match('/^(.*?)\.\s*[\'"]([a-z0-9_]+)[\'"]\s*$/is', $expr, $m)
        && stripos($m[1], 'prefix') === false) {
        $left = resolve_expr_to_bare($m[1], $symbols, $varMap, $localMethods);
        if ($left !== null) {
            return $left . strtolower($m[2]);
        }
    }

    // $wpdb->prefix . 'bcc_literal'
    if (preg_match('/prefix\s*\.\s*[\'"]([a-z0-9_]+)[\'"]/i', $expr, $m)) {
        return bare_name($m[1]);
    }
    // DB::table('x')
    if (preg_match('/DB::table\(\s*[\'"]([a-z0-9_]+)[\'"]\s*\)/i', $expr, $m)) {
        return 'bcc_' . strtolower($m[1]);
    }
    // Class::method()  (self::table(), TableRegistry::votes(), DisputeRepository::disputes_table(), …)
    //
    // Method names like table() collide across files, so the file-LOCAL
    // method map (methods defined in the same file, e.g. a Repository's own
    // self::table()) takes precedence over the ambiguous global map. The
    // global map is only consulted for names unique enough to trust.
    if (preg_match('/(?:\\\\?[a-z0-9_\\\\]+::)?([a-z0-9_]+)\s*\(\s*\)/i', $expr, $m)) {
        $mname = strtolower($m[1]);
        if (isset($localMethods[$mname])) {
            return $localMethods[$mname];
        }
        if (isset($symbols['funcs'][$m[1]])) {
            return $symbols['funcs'][$m[1]];
        }
        if (isset($symbols['methods'][$mname]) && !$symbols['ambiguous'][$mname]) {
            return $symbols['methods'][$mname];
        }
    }
    // Bare variable reference -> look up in this file's varMap.
    if (preg_match('/^\$([a-z0-9_]+)$/i', $expr, $m) && isset($varMap[$m[1]])) {
        return $varMap[$m[1]];
    }

    return null;
}

/**
 * Parse the index declarations out of a CREATE TABLE body.
 *
 * @return array<string, string>  index name => canonical column tuple
 */
function parse_indexes(string $body): array {
    $idx = [];
    foreach (explode("\n", $body) as $line) {
        $line = trim($line);

        // PRIMARY KEY (...)
        if (preg_match('/^PRIMARY\s+KEY\s*\(([^)]*)\)/i', $line, $m)) {
            $idx['PRIMARY'] = canon_cols($m[1]);
            continue;
        }
        // UNIQUE KEY name (...) / KEY name (...) / INDEX name (...)
        if (preg_match('/^(?:UNIQUE\s+KEY|UNIQUE\s+INDEX|KEY|INDEX)\s+`?([a-z0-9_]+)`?\s*\(([^)]*)\)/i', $line, $m)) {
            $idx[strtolower($m[1])] = canon_cols($m[2]);
        }
    }
    return $idx;
}

/**
 * Scan a file for CREATE TABLE statements; return declared tables with indexes.
 *
 * @return array<string, array{file: string, indexes: array<string,string>}>
 */
function scan_declared_in_file(string $file, array $symbols): array {
    $src = (string) file_get_contents($file);
    $out = [];

    // File-local method map: a Repository's OWN self::table() must beat the
    // ambiguous global table() symbol that collides across many files.
    $localMethods = file_methods($file);

    // Local variable map for this file: var name => bare table (best-effort).
    $varMap = [];
    if (preg_match_all('/\$([a-z0-9_]+)\s*=\s*([^;]+);/i', $src, $am, PREG_SET_ORDER)) {
        // Two passes so a var defined after a concat-source still resolves
        // forward references on the second sweep.
        for ($pass = 0; $pass < 2; $pass++) {
            foreach ($am as $a) {
                $bare = resolve_expr_to_bare($a[2], $symbols, $varMap, $localMethods);
                if ($bare !== null) {
                    $varMap[$a[1]] = $bare;
                }
            }
        }
    }

    // Find each CREATE TABLE [IF NOT EXISTS] <expr> ( ... )  — but skip
    // `CREATE TABLE x LIKE y` (no column body) which we handle separately.
    if (preg_match_all(
        '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(\{?\$?[a-z0-9_\\\\>{}\'"\(\): .-]*?)\s*\((.*?)\n\s*\)\s*(?:ENGINE|[\$\{]|;)/is',
        $src,
        $cm,
        PREG_SET_ORDER
    )) {
        foreach ($cm as $c) {
            $tableExpr = trim($c[1], " {}$`");
            // Normalize `{$var}` / `$var` / `$wpdb->prefix . '...'` etc.
            $tableExpr = preg_replace('/[{}]/', '', $c[1]) ?? $c[1];
            $bare = resolve_expr_to_bare(ltrim($tableExpr), $symbols, $varMap, $localMethods);
            if ($bare === null) {
                continue; // could not resolve — reported by the unresolved counter
            }
            $out[$bare] = ['file' => $file, 'indexes' => parse_indexes($c[2])];
        }
    }

    // Handle `CREATE TABLE IF NOT EXISTS $x LIKE $y` (archive tables): the
    // table is declared even though its columns/indexes mirror the source.
    if (preg_match_all(
        '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(\$?[a-z0-9_]+)\s+LIKE\s+(\$?[a-z0-9_]+)/i',
        $src,
        $lm,
        PREG_SET_ORDER
    )) {
        foreach ($lm as $l) {
            $expr = ltrim($l[1], '$');
            $bare = isset($varMap[$expr]) ? $varMap[$expr] : resolve_expr_to_bare($l[1], $symbols, $varMap, $localMethods);
            if ($bare !== null && !isset($out[$bare])) {
                // indexes mirror source; not compared. `like` marks the
                // entry so the ALTER scan below can't accidentally give it
                // a partial index set and ENABLE the comparison against a
                // full inherited live set.
                $out[$bare] = ['file' => $file, 'indexes' => [], 'like' => true];
            }
        }
    }

    // `ALTER TABLE <expr> ... ADD [UNIQUE] KEY|INDEX name (cols)` — self-heal
    // migrations declare indexes OUTSIDE the CREATE (e.g. bcc_disputes'
    // uq_active_vote rides a STORED generated column that dbDelta can't
    // express, so it is added by an option-guarded ALTER). Those are
    // declarations too; without this scan the guard reports them as
    // live-only drift forever. One ALTER can carry multiple ADDs.
    //
    // Variable table expressions (`{$table}`) resolve against the NEAREST
    // PRECEDING assignment of that variable — a file-global var map would
    // pick the LAST `$table = …` in the file, which in a repository with
    // several migrations (DisputeRepository assigns `$table` per method)
    // attributes the ALTER to the wrong table.
    if (preg_match_all(
        '/ALTER\s+TABLE\s+(\{?\$?[a-z0-9_\\\\>{}\'"\(\): .-]+?)\s+((?:(?!ALTER\s+TABLE)[^;"])*)/is',
        $src,
        $tm,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    )) {
        foreach ($tm as $t) {
            $tableExpr = preg_replace('/[{}]/', '', $t[1][0]) ?? $t[1][0];
            $tableExpr = ltrim($tableExpr);
            $offset    = (int) $t[0][1];

            $bare = null;
            if (preg_match('/^\$([a-z0-9_]+)$/i', $tableExpr, $vm)) {
                // Nearest-preceding assignment of this variable.
                if (preg_match_all(
                    '/\$' . preg_quote($vm[1], '/') . '\s*=\s*([^;]+);/i',
                    substr($src, 0, $offset),
                    $pm,
                    PREG_SET_ORDER
                ) && $pm !== []) {
                    $last = $pm[count($pm) - 1][1];
                    $bare = resolve_expr_to_bare($last, $symbols, $varMap, $localMethods);
                }
            } else {
                $bare = resolve_expr_to_bare($tableExpr, $symbols, $varMap, $localMethods);
            }
            if ($bare === null) {
                continue;
            }

            if (preg_match_all(
                '/ADD\s+(UNIQUE\s+)?(?:KEY|INDEX)\s+`?([a-z0-9_]+)`?\s*\(([^)]*)\)/i',
                $t[2][0],
                $im,
                PREG_SET_ORDER
            )) {
                if (isset($out[$bare]['like'])) {
                    // LIKE-derived table (archives): its live index set is
                    // inherited wholesale from the source, so a partial
                    // ALTER-declared set must not enable the comparison.
                    continue;
                }
                if (!isset($out[$bare])) {
                    $out[$bare] = ['file' => $file, 'indexes' => []];
                }
                foreach ($im as $i) {
                    $out[$bare]['indexes'][strtolower($i[2])] = canon_cols($i[3]);
                }
            }
        }
    }

    return $out;
}

/** @var array<string, array{file:string, indexes: array<string,string>}> */
$declaredTables = [];
foreach ($declaredFiles as $file) {
    foreach (scan_declared_in_file($file, $SYMBOLS) as $bare => $info) {
        // First declaration wins for index comparison; keep it stable.
        if (!isset($declaredTables[$bare])) {
            $declaredTables[$bare] = $info;
        }
    }
}

// Belt-and-suspenders: fold TableRegistry::all() literals in as declared
// table names (even if a CREATE TABLE parse somehow missed one).
if (is_readable($TABLE_REG)) {
    $regSrc = (string) file_get_contents($TABLE_REG);
    if (preg_match_all('/prefix\s*\.\s*[\'"]([a-z0-9_]+)[\'"]/i', $regSrc, $rm)) {
        foreach ($rm[1] as $lit) {
            $bare = bare_name($lit);
            if (!isset($declaredTables[$bare])) {
                $declaredTables[$bare] = ['file' => $TABLE_REG . ' (TableRegistry accessor)', 'indexes' => []];
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| (2) DOCUMENTED — parse the master-inventory table in database-schema.md
|--------------------------------------------------------------------------
| Row shape: | Table | ~rows | Purpose | Owning code | Status |
| We extract the bare table name + whether Status says Active vs ORPHAN.
*/

/** @return array<string, array{status: string, raw: string}>  bare => meta */
function parse_documented(string $doc): array {
    $lines = explode("\n", (string) file_get_contents($doc));
    $out   = [];
    foreach ($lines as $line) {
        if (strpos($line, '|') !== 0 && strpos(ltrim($line), '|') !== 0) {
            continue;
        }
        // \r in the charlist: the doc is CRLF, and without it the trailing
        // pipe survives (`|\r`), every row grows an empty last cell, and the
        // status column reads as '' → Active for ALL rows (RETIRED/ORPHAN
        // classification silently never fired).
        $cells = array_map('trim', explode('|', trim($line, " \t\r|")));
        if (count($cells) < 5) {
            continue;
        }
        $tableCell = $cells[0];
        if (!preg_match('/^wp_bcc_[a-z0-9_]+$/i', $tableCell)) {
            continue; // header / separator / non-table row
        }
        $statusCell = $cells[count($cells) - 1];
        // RETIRED rows (actively dropped by a migration, e.g. user_ranks)
        // behave like ORPHAN for parity purposes: NOT expected in code or
        // live. Only a literal Active status counts as Active — the old
        // "anything not orphan is Active" default silently promoted
        // RETIRED rows and produced a permanent false parity finding.
        if (stripos($statusCell, 'orphan') !== false || stripos($statusCell, 'retired') !== false) {
            $status = 'ORPHAN';
        } else {
            $status = 'Active';
        }
        $out[bare_name($tableCell)] = ['status' => $status, 'raw' => $tableCell];
    }
    return $out;
}

$documented = parse_documented($SCHEMA_DOC);
$docActive  = array_filter($documented, static fn(array $m): bool => $m['status'] === 'Active');
$docOrphan  = array_filter($documented, static fn(array $m): bool => $m['status'] === 'ORPHAN');

/*
|--------------------------------------------------------------------------
| (3) LIVE — optional mysqli connection
|--------------------------------------------------------------------------
*/

/**
 * @return array{conn: ?mysqli, prefix: string, note: string}
 */
function connect_live(string $localSiteJson): array {
    if (!function_exists('mysqli_connect')) {
        return ['conn' => null, 'prefix' => 'wp_', 'note' => 'mysqli extension not available in this PHP CLI'];
    }

    $host = getenv('BCC_DB_HOST') ?: '';
    $port = getenv('BCC_DB_PORT') ?: '';
    $user = getenv('BCC_DB_USER') ?: '';
    $pass = getenv('BCC_DB_PASS');
    $name = getenv('BCC_DB_NAME') ?: '';

    if ($host === '' || $user === '' || $name === '') {
        // Fall back to local-site.json.
        if (is_readable($localSiteJson)) {
            $json = json_decode((string) file_get_contents($localSiteJson), true);
            if (is_array($json) && isset($json['mysql'])) {
                $host = $host !== '' ? $host : '127.0.0.1';
                $user = $user !== '' ? $user : (string) ($json['mysql']['user'] ?? '');
                $pass = $pass !== false ? $pass : (string) ($json['mysql']['password'] ?? '');
                $name = $name !== '' ? $name : (string) ($json['mysql']['database'] ?? '');
                if ($port === '' && isset($json['services']['mysql']['ports']['MYSQL'][0])) {
                    $port = (string) $json['services']['mysql']['ports']['MYSQL'][0];
                }
            }
        }
    }
    if ($pass === false) {
        $pass = '';
    }
    if ($host === '' || $user === '' || $name === '' || $port === '') {
        return ['conn' => null, 'prefix' => 'wp_', 'note' => 'no connection params resolvable'];
    }

    // Suppress connect warnings; we want a clean skip, not a fatal.
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = @mysqli_connect($host, $user, (string) $pass, $name, (int) $port);
    if (!($conn instanceof mysqli)) {
        return ['conn' => null, 'prefix' => 'wp_', 'note' => "connect failed to {$host}:{$port}/{$name}"];
    }

    // Determine the real prefix from the DB by probing for a bcc table.
    $prefix = 'wp_';
    $res = @mysqli_query($conn, "SHOW TABLES LIKE '%bcc_%'");
    if ($res instanceof mysqli_result) {
        while ($row = mysqli_fetch_row($res)) {
            $tbl = (string) $row[0];
            if (preg_match('/^(.*?)bcc_/', $tbl, $m)) {
                $prefix = $m[1];
                break;
            }
        }
        mysqli_free_result($res);
    }

    return ['conn' => $conn, 'prefix' => $prefix, 'note' => "connected to {$host}:{$port}/{$name} (prefix {$prefix})"];
}

$live = connect_live($LOCAL_SITE);
/** @var ?mysqli $liveConn */
$liveConn  = $live['conn'];
$livePrefix = $live['prefix'];

/** @var array<string, array<string,string>>  bare table => (index name => cols) */
$liveTables = [];
if ($liveConn instanceof mysqli) {
    $res = @mysqli_query($liveConn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($liveConn, $livePrefix) . "bcc_%'");
    if ($res instanceof mysqli_result) {
        $names = [];
        while ($row = mysqli_fetch_row($res)) {
            $names[] = (string) $row[0];
        }
        mysqli_free_result($res);

        foreach ($names as $full) {
            $bare = bare_name($full);
            $liveTables[$bare] = [];
            $ir = @mysqli_query($liveConn, 'SHOW INDEX FROM `' . str_replace('`', '', $full) . '`');
            if ($ir instanceof mysqli_result) {
                // Group columns by Key_name preserving Seq_in_index order.
                $grouped = [];
                while ($r = mysqli_fetch_assoc($ir)) {
                    $key = (string) $r['Key_name'];
                    $seq = (int) $r['Seq_in_index'];
                    $col = strtolower((string) $r['Column_name']);
                    $grouped[$key][$seq] = $col;
                }
                mysqli_free_result($ir);
                foreach ($grouped as $key => $cols) {
                    ksort($cols);
                    $liveTables[$bare][$key] = implode(',', array_values($cols));
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| CHECKS
|--------------------------------------------------------------------------
*/

$failFindings = []; // drift -> exit 1
$infoFindings = []; // expected pre-Phase-5 -> informational only

echo "========================================================\n";
echo " SCHEMA-DRIFT GUARD  (armed in CI: static mode)\n";
echo "========================================================\n";
echo "Repo root : {$REPO_ROOT}\n";
echo "Declared  : " . count($declaredTables) . " tables (CREATE TABLE + TableRegistry)\n";
echo "Documented: " . count($documented) . " tables ("
   . count($docActive) . " Active, " . count($docOrphan) . " ORPHAN)\n";
if ($liveConn instanceof mysqli) {
    echo "Live      : " . count($liveTables) . " {$livePrefix}bcc_* tables — {$live['note']}\n";
} else {
    echo "Live      : LIVE CHECK SKIPPED (no DB reachable — {$live['note']})\n";
}

/* ---- CHECK 1: static table parity (DECLARED vs DOCUMENTED) ------------ */

echo "\n--- CHECK 1: static table parity (DECLARED vs DOCUMENTED) ---\n";

$c1 = [];

// 1a: every DOCUMENTED-Active table must be DECLARED in code.
foreach ($docActive as $bare => $meta) {
    if (!isset($declaredTables[$bare])) {
        $msg = "documented-Active '{$meta['raw']}' is NOT declared in code (no CREATE TABLE / TableRegistry accessor)";
        $c1[] = $msg;
        $failFindings[] = "[parity] {$msg}";
    }
}

// 1b: every DECLARED table must appear in the doc (Active or ORPHAN).
foreach ($declaredTables as $bare => $info) {
    if (!isset($documented[$bare])) {
        $msg = "declared table 'wp_{$bare}' is NOT in docs/database-schema.md (regenerate the doc) — declared in " . basename($info['file']);
        $c1[] = $msg;
        $failFindings[] = "[parity] {$msg}";
    }
}

// Note: documented-ORPHAN tables are EXPECTED to be absent from declared
// code; we do NOT flag those (that's the whole point of the orphan list).

if ($c1 === []) {
    echo "  OK — declared and documented-Active table sets agree.\n";
} else {
    foreach ($c1 as $f) {
        echo "  DRIFT: {$f}\n";
    }
}

/* ---- CHECK 2: LIVE drift (only when connected) ------------------------ */

if ($liveConn instanceof mysqli) {

    /* 2a: live tables vs documented. */
    echo "\n--- CHECK 2a: live tables vs documented ---\n";
    $c2a = [];

    foreach ($liveTables as $bare => $_) {
        if (!isset($documented[$bare])) {
            $msg = "live '{$livePrefix}{$bare}' is UNDOCUMENTED (regenerate docs/database-schema.md)";
            $c2a[] = "DRIFT: {$msg}";
            $failFindings[] = "[live-table] {$msg}";
        }
    }
    foreach ($docActive as $bare => $meta) {
        if (!isset($liveTables[$bare])) {
            $msg = "documented-Active '{$meta['raw']}' is MISSING from live DB";
            $c2a[] = "DRIFT: {$msg}";
            $failFindings[] = "[live-table] {$msg}";
        }
    }
    foreach ($docOrphan as $bare => $meta) {
        if (isset($liveTables[$bare])) {
            $msg = "ORPHAN '{$meta['raw']}' still present live (expected; Phase 5 guarded drop)";
            $c2a[] = "INFO : {$msg}";
            $infoFindings[] = "[orphan-live] {$msg}";
        }
    }

    if ($c2a === []) {
        echo "  OK — live tables match documented inventory.\n";
    } else {
        foreach ($c2a as $f) {
            echo "  {$f}\n";
        }
    }

    /* 2b: live indexes vs declared indexes (the main dbDelta-leftover target). */
    echo "\n--- CHECK 2b: live indexes vs declared (dbDelta leftovers) ---\n";
    $c2b = [];

    foreach ($liveTables as $bare => $liveIdx) {
        if (!isset($declaredTables[$bare])) {
            continue; // not declared -> not comparable here (caught in 1b/2a)
        }
        $declIdx = $declaredTables[$bare]['indexes'];
        if ($declIdx === []) {
            // LIKE-derived or accessor-only table: no parsed index set to compare.
            continue;
        }

        // Map declared by canonical column-tuple for name-agnostic dup detection.
        $declByName = array_change_key_case($declIdx, CASE_LOWER);
        $declByCols = [];
        foreach ($declIdx as $n => $cols) {
            $declByCols[$cols][] = $n;
        }

        // live-index-not-declared (by name) — the leftover/duplicate target.
        foreach ($liveIdx as $lname => $lcols) {
            $lkey = strtolower($lname);
            if ($lkey === 'primary') {
                continue; // PK name is engine-fixed; compared via column set below
            }
            if (!isset($declByName[$lkey])) {
                $dupOf = isset($declByCols[$lcols]) ? implode('/', $declByCols[$lcols]) : null;
                $extra = $dupOf !== null
                    ? " (same columns as declared '{$dupOf}' — dbDelta duplicate-index leftover)"
                    : '';
                $msg = "wp_{$bare}: live index '{$lname}' ({$lcols}) NOT declared{$extra}";
                $c2b[] = "DRIFT: {$msg}";
                $failFindings[] = "[live-index] {$msg}";
            }
        }

        // declared-index-not-live.
        foreach ($declIdx as $dname => $dcols) {
            $dkey = strtolower($dname);
            $liveByName = array_change_key_case($liveIdx, CASE_LOWER);
            if ($dkey !== 'primary' && !isset($liveByName[$dkey])) {
                $msg = "wp_{$bare}: declared index '{$dname}' ({$dcols}) MISSING from live";
                $c2b[] = "DRIFT: {$msg}";
                $failFindings[] = "[live-index] {$msg}";
            }
        }

        // Exact-duplicate live indexes: two live keys over identical columns.
        $liveByCols = [];
        foreach ($liveIdx as $lname => $lcols) {
            if (strtolower($lname) === 'primary') {
                continue;
            }
            $liveByCols[$lcols][] = $lname;
        }
        foreach ($liveByCols as $cols => $names) {
            if (count($names) > 1) {
                $msg = "wp_{$bare}: duplicate live indexes over ({$cols}): " . implode(', ', $names);
                $c2b[] = "DRIFT: {$msg}";
                $failFindings[] = "[live-dup-index] {$msg}";
            }
        }
    }

    if ($c2b === []) {
        echo "  OK — live indexes match declared (no dbDelta leftovers).\n";
    } else {
        foreach ($c2b as $f) {
            echo "  {$f}\n";
        }
    }

    mysqli_close($liveConn);
} else {
    echo "\n--- CHECK 2 (live drift): SKIPPED — running static-only ---\n";
}

/*
|--------------------------------------------------------------------------
| SUMMARY
|--------------------------------------------------------------------------
*/

echo "\n========================================================\n";
echo " SUMMARY\n";
echo "========================================================\n";
echo "  Drift findings (fail) : " . count($failFindings) . "\n";
echo "  Info  findings        : " . count($infoFindings) . "\n";

if ($infoFindings !== []) {
    echo "\n  Informational (expected pre-Phase-5):\n";
    foreach ($infoFindings as $f) {
        echo "    - {$f}\n";
    }
}

if ($failFindings === []) {
    echo "\nPASS: no schema drift detected"
       . ($liveConn instanceof mysqli ? " (declared == documented == live)." : " (static-only: declared == documented).")
       . "\n";
    exit(0);
}

echo "\nFAIL: " . count($failFindings) . " schema-drift finding(s):\n";
foreach ($failFindings as $f) {
    echo "  - {$f}\n";
}
echo "\nDrift was reconciled to zero on 2026-07-23 (Tier-3 sweep: parser\n";
echo "fixes + drop-legacy-indexes v2 + database-schema.md regen), so any\n";
echo "finding above is NEW drift — fix the declaration, the doc, or the\n";
echo "DB (see drop-legacy-indexes.php for the drop idiom), don't allowlist.\n";
exit(1);
