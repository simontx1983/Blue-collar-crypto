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
 *   - documented-Active tables missing from live;
 *   - COLUMN drift (CHECK 3, issue #132, static): each documented-Active
 *     table's column block in docs/database-schema.md must match the column
 *     set its shipped schema code produces (CREATE TABLE ± ALTER ADD / DROP
 *     / rename, migration maps resolved), in both directions, and every
 *     such table must have a block. tests/ and scripts/ files and PHP
 *     comments never declare a column; a column only a hand-run script adds
 *     is documented `OPTIONAL`. Run against the doc as it stood before the
 *     #132 docs fix, it reports exactly the 20 real drifts plus the 8
 *     missing blocks, and nothing else.
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
 * INFO-class observations (documented-ORPHAN/RETIRED tables still LIVE) are
 * printed but do NOT by themselves set a non-zero exit — a dropped table can
 * linger in a database until its guarded drop runs there.
 *
 * Still DECLARED in code is different, and static: a documented-ORPHAN or
 * documented-RETIRED table that a CREATE TABLE / TableRegistry accessor
 * still declares FAILS (CHECK 1c, issue #162) — except a RETIRED table named
 * in the RETIREMENT_WINDOW allowlist (INFO), whose entries themselves fail
 * once stale. So does any inventory row
 * whose Status cell is not exactly `Active`, `ORPHAN` or `RETIRED`, or a
 * table listed twice (CHECK 1d).
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
 * Resolve the table an `ALTER TABLE <expr>` at $offset in $src names.
 *
 * Variable table expressions (`{$table}`) resolve against the NEAREST
 * PRECEDING assignment of that variable — a file-global var map would pick
 * the LAST `$table = …` in the file, which in a repository with several
 * migrations (DisputeRepository assigns `$table` per method) attributes the
 * ALTER to the wrong table.
 */
function resolve_alter_table(string $src, int $offset, string $rawExpr, array $symbols, array $varMap, array $localMethods): ?string {
    $tableExpr = preg_replace('/[{}]/', '', $rawExpr) ?? $rawExpr;
    $tableExpr = ltrim($tableExpr);

    if (preg_match('/^\$([a-z0-9_]+)$/i', $tableExpr, $vm)) {
        // Nearest-preceding assignment of this variable.
        if (preg_match_all(
            '/\$' . preg_quote($vm[1], '/') . '\s*=\s*([^;]+);/i',
            substr($src, 0, $offset),
            $pm,
            PREG_SET_ORDER
        ) && $pm !== []) {
            $last = $pm[count($pm) - 1][1];
            return resolve_expr_to_bare($last, $symbols, $varMap, $localMethods);
        }
        return null;
    }
    return resolve_expr_to_bare($tableExpr, $symbols, $varMap, $localMethods);
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
            $bare = resolve_alter_table($src, (int) $t[0][1], $t[1][0], $symbols, $varMap, $localMethods);
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
| (1b) DECLARED COLUMNS — the column set each table's schema code produces
|--------------------------------------------------------------------------
| Feeds CHECK 3 (issue #132). Deliberately STRICTER about its inputs than the
| table/index scan above, which is left exactly as it was:
|
|   - Only shipped schema code counts. Files under a plugin's tests/ or
|     scripts/ directory are excluded: a test fixture's CREATE/ALTER is not
|     the schema, and scripts/ holds HAND-RUN tools that no install executes
|     (a column only they add is documented OPTIONAL — see the doc legend).
|   - Comments are blanked first (same length, newlines kept), so an ALTER
|     quoted in a docblock is not read as a migration.
|
| Final set per table = its CREATE TABLE columns
|                       + columns an ALTER … ADD [COLUMN] adds
|                       − columns an ALTER … DROP [COLUMN] drops
|                       ± CHANGE / RENAME COLUMN renames.
| An ADD whose column is a VARIABLE (`ADD COLUMN {$column} {$ddl}`, or a whole
| `{$clause}` of `ADD COLUMN …` fragments) is resolved through the enclosing
| `foreach (X as $k => $v)` to the keys of X — an array literal assigned in
| the same file, or the `return [...]` of a named function. That is how the
| migration-map idiom (schema-collections.php) declares columns. An ADD that
| cannot be resolved is a FAILURE, never a skip: an unreadable migration must
| not make its columns silently undeclared.
| `CREATE TABLE x LIKE y` inherits y's final set.
*/

/** Leading words of a CREATE TABLE body line that are NOT a column. */
const NON_COLUMN_LEADS = ['PRIMARY', 'KEY', 'UNIQUE', 'INDEX', 'CONSTRAINT', 'FULLTEXT', 'SPATIAL', 'FOREIGN', 'CHECK'];

/** Blank out PHP comments, keeping every byte offset and newline. */
function strip_php_comments(string $src): string {
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok) && ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT)) {
            $out .= (string) preg_replace('/[^\n]/', ' ', $tok[1]);
            continue;
        }
        $out .= is_array($tok) ? $tok[1] : $tok;
    }
    return $out;
}

/** 1-based line number of byte $offset in $src. */
function line_at(string $src, int $offset): int {
    return substr_count(substr($src, 0, $offset), "\n") + 1;
}

/** Whether $file is a test fixture or a hand-run script (excluded from 1b). */
function is_test_or_script(string $file): bool {
    return preg_match('#/bcc-[^/]+/(?:tests|scripts)/#', str_replace('\\', '/', $file)) === 1;
}

/**
 * The bracketed array literal starting at the `[` at $open, or null.
 * Quote-aware, so brackets inside string literals do not count.
 */
function bracket_literal(string $src, int $open): ?string {
    $depth = 0;
    $len   = strlen($src);
    for ($i = $open; $i < $len; $i++) {
        $ch = $src[$i];
        if ($ch === '"' || $ch === "'") {
            // Advance the SAME cursor past the string literal.
            for ($i++; $i < $len && $src[$i] !== $ch; $i++) {
                if ($src[$i] === '\\') {
                    $i++;
                }
            }
            continue;
        }
        if ($ch === '[') {
            $depth++;
        } elseif ($ch === ']') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $open, $i - $open + 1);
            }
        }
    }
    return null;
}

/** @return array<string, string> string key => string value ('' when not a string) */
function array_literal_pairs(string $literal): array {
    $out = [];
    preg_match_all(
        '/([\'"])([a-z_][a-z0-9_]*)\1\s*=>\s*(?:([\'"])((?:\\\\.|(?!\3).)*)\3)?/is',
        $literal,
        $m,
        PREG_SET_ORDER
    );
    foreach ($m as $x) {
        $out[strtolower($x[2])] = $x[4] ?? '';
    }
    return $out;
}

/**
 * `function name(...)` => the pairs of the first `return [...]` in its body,
 * across every shipped file — the migration-map functions
 * (`bcc_onchain_metadata_state_columns()` etc.).
 *
 * @return array<string, array<string,string>>
 */
function function_return_arrays(array $files): array {
    $out = [];
    foreach ($files as $file) {
        if (is_test_or_script($file)) {
            continue;
        }
        $src = strip_php_comments((string) file_get_contents($file));
        if (!preg_match_all('/\bfunction\s+([a-z0-9_]+)\s*\(/i', $src, $fm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($fm as $k => $f) {
            $start = (int) $f[0][1];
            $end   = isset($fm[$k + 1]) ? (int) $fm[$k + 1][0][1] : strlen($src);
            $body  = substr($src, $start, $end - $start);
            if (preg_match('/\breturn\s*\[/', $body, $rm, PREG_OFFSET_CAPTURE)) {
                $lit = bracket_literal($body, (int) $rm[0][1] + strlen($rm[0][0]) - 1);
                if ($lit !== null) {
                    $out[strtolower($f[1][0])] = array_literal_pairs($lit);
                }
            }
        }
    }
    return $out;
}

/** @return array<string,int> column (lower-case) => line in $src */
function parse_create_columns(string $body, int $baseLine): array {
    $out = [];
    foreach (explode("\n", $body) as $i => $line) {
        if (!preg_match('/^\s*`?([a-z_][a-z0-9_]*)`?\s+[a-z]/i', $line, $m)) {
            continue;
        }
        if (in_array(strtoupper($m[1]), NON_COLUMN_LEADS, true)) {
            continue;
        }
        $out[strtolower($m[1])] = $baseLine + $i;
    }
    return $out;
}

/**
 * Columns a variable in an ALTER resolves to, through the nearest preceding
 * `foreach (X as $k => $v)` that binds it. Null = unresolvable.
 *
 * @param bool $clause  true when the variable is the WHOLE clause (each value
 *                      must then be an `ADD COLUMN <key> …` fragment)
 * @return list<string>|null
 */
function resolve_column_variable(string $src, int $offset, string $var, bool $clause, array $funcArrays): ?array {
    $before = substr($src, 0, $offset);
    if (!preg_match_all(
        '/\bforeach\s*\(\s*(.+?)\s+as\s+\$([a-z0-9_]+)\s*(?:=>\s*\$([a-z0-9_]+)\s*)?\)/is',
        $before,
        $fm,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    )) {
        return null;
    }
    for ($k = count($fm) - 1; $k >= 0; $k--) {
        $f     = $fm[$k];
        $first = $f[2][0];
        $value = isset($f[3]) && $f[3][1] >= 0 ? $f[3][0] : null;
        $isKey = $value !== null && $first === $var;
        $isVal = $value === null ? $first === $var : $value === $var;
        if (!$isKey && !$isVal) {
            continue;
        }
        $expr  = trim($f[1][0]);
        $pairs = null;
        if (preg_match('/^\$([a-z0-9_]+)$/i', $expr, $vm)) {
            $upto = substr($src, 0, (int) $f[0][1]);
            if (preg_match_all('/\$' . preg_quote($vm[1], '/') . '\s*=\s*\[/', $upto, $am, PREG_OFFSET_CAPTURE) && $am[0] !== []) {
                $last = $am[0][count($am[0]) - 1];
                $lit  = bracket_literal($upto, (int) $last[1] + strlen($last[0]) - 1);
                $pairs = $lit === null ? null : array_literal_pairs($lit);
            }
        } elseif (preg_match('/^\\\\?([a-z0-9_]+)\s*\(\s*\)$/i', $expr, $cm)) {
            $pairs = $funcArrays[strtolower($cm[1])] ?? null;
        }
        if ($pairs === null || $pairs === []) {
            return null;
        }
        if ($isKey && !$clause) {
            return array_keys($pairs); // ADD COLUMN {$key} {$ddl}
        }
        if ($clause && !$isKey) {
            // {$clause} = 'ADD COLUMN <key> …': every fragment must add its key.
            foreach ($pairs as $key => $fragment) {
                if (!preg_match('/^\s*ADD\s+(?:COLUMN\s+)?`?' . preg_quote($key, '/') . '`?\s/i', $fragment)) {
                    return null;
                }
            }
            return array_keys($pairs);
        }
        return null;
    }
    return null;
}

/**
 * @return array{
 *   creates: array<string, list<array{cols: array<string,int>, file: string}>>,
 *   like: array<string, ?string>,
 *   ops: array<string, list<array{op: string, col: string, to?: string, where: string}>>,
 *   problems: list<string>
 * }
 */
function scan_declared_columns_in_file(string $file, array $symbols, array $funcArrays, string $relBase): array {
    $res = ['creates' => [], 'like' => [], 'ops' => [], 'problems' => []];
    $src = strip_php_comments((string) file_get_contents($file));
    $rel = ltrim(str_replace([str_replace('\\', '/', $relBase), '\\'], ['', '/'], str_replace('\\', '/', $file)), '/');

    $localMethods = file_methods($file);
    $varMap = [];
    if (preg_match_all('/\$([a-z0-9_]+)\s*=\s*([^;]+);/i', $src, $am, PREG_SET_ORDER)) {
        for ($pass = 0; $pass < 2; $pass++) {
            foreach ($am as $a) {
                $bare = resolve_expr_to_bare($a[2], $symbols, $varMap, $localMethods);
                if ($bare !== null) {
                    $varMap[$a[1]] = $bare;
                }
            }
        }
    }

    // Same CREATE TABLE shape the table scan uses, with the body's offset.
    if (preg_match_all(
        '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(\{?\$?[a-z0-9_\\\\>{}\'"\(\): .-]*?)\s*\((.*?)\n\s*\)\s*(?:ENGINE|[\$\{]|;)/is',
        $src,
        $cm,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    )) {
        foreach ($cm as $c) {
            $tableExpr = preg_replace('/[{}]/', '', $c[1][0]) ?? $c[1][0];
            $bare = resolve_expr_to_bare(ltrim($tableExpr), $symbols, $varMap, $localMethods);
            if ($bare === null) {
                continue;
            }
            $res['creates'][$bare][] = [
                'cols' => parse_create_columns($c[2][0], line_at($src, (int) $c[2][1])),
                'file' => $rel,
            ];
        }
    }

    if (preg_match_all(
        '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(\$?[a-z0-9_]+)\s+LIKE\s+(\$?[a-z0-9_]+)/i',
        $src,
        $lm,
        PREG_SET_ORDER
    )) {
        foreach ($lm as $l) {
            $bare = $varMap[ltrim($l[1], '$')] ?? resolve_expr_to_bare($l[1], $symbols, $varMap, $localMethods);
            if ($bare !== null) {
                $res['like'][$bare] = $varMap[ltrim($l[2], '$')] ?? resolve_expr_to_bare($l[2], $symbols, $varMap, $localMethods);
            }
        }
    }

    $kw = '(?:UNIQUE|KEY|INDEX|PRIMARY|CONSTRAINT|FULLTEXT|SPATIAL|FOREIGN|CHECK|COLUMN|PARTITION)\b';
    if (preg_match_all(
        '/ALTER\s+TABLE\s+(\{?\$?[a-z0-9_\\\\>{}\'"\(\): .-]+?)\s+((?:(?!ALTER\s+TABLE)[^;"])*)/is',
        $src,
        $tm,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    )) {
        foreach ($tm as $t) {
            $offset = (int) $t[0][1];
            $clause = $t[2][0];
            $where  = $rel . ':' . line_at($src, $offset);

            $adds = [];
            $unresolved = false;
            // ADD [COLUMN] <literal> <type>
            if (preg_match_all('/\bADD\s+(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?(?!' . $kw . ')`?([a-z_][a-z0-9_]*)`?\s+[a-z]/i', $clause, $m)) {
                foreach ($m[1] as $col) {
                    $adds[] = strtolower($col);
                }
            }
            // ADD [COLUMN] {$var} …, and a whole-clause variable
            // (`ALTER TABLE {$t} {$clause}`) — each resolved through its foreach.
            $varAdds = [];
            if (preg_match_all('/\bADD\s+(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?\{?\$([a-z0-9_]+)\}?/i', $clause, $vm)) {
                foreach ($vm[1] as $var) {
                    $varAdds[] = [$var, false];
                }
            }
            if (preg_match('/^\s*\{?\$([a-z0-9_]+)\}?\s*$/i', $clause, $wm)) {
                $varAdds[] = [$wm[1], true];
            }
            foreach ($varAdds as [$var, $isClause]) {
                $cols = resolve_column_variable($src, $offset, $var, $isClause, $funcArrays);
                if ($cols === null) {
                    $unresolved = true;
                } else {
                    array_push($adds, ...$cols);
                }
            }

            $drops = [];
            if (preg_match_all('/\bDROP\s+(?:COLUMN\s+)?(?:IF\s+EXISTS\s+)?(?!' . $kw . ')`?([a-z_][a-z0-9_]*)`?/i', $clause, $m)) {
                foreach ($m[1] as $col) {
                    $drops[] = strtolower($col);
                }
            }
            $renames = [];
            if (preg_match_all('/\bCHANGE\s+(?:COLUMN\s+)?`?([a-z_][a-z0-9_]*)`?\s+`?([a-z_][a-z0-9_]*)`?/i', $clause, $m, PREG_SET_ORDER)) {
                foreach ($m as $x) {
                    $renames[] = [strtolower($x[1]), strtolower($x[2])];
                }
            }
            if (preg_match_all('/\bRENAME\s+COLUMN\s+`?([a-z_][a-z0-9_]*)`?\s+TO\s+`?([a-z_][a-z0-9_]*)`?/i', $clause, $m, PREG_SET_ORDER)) {
                foreach ($m as $x) {
                    $renames[] = [strtolower($x[1]), strtolower($x[2])];
                }
            }

            if ($adds === [] && $drops === [] && $renames === [] && !$unresolved) {
                continue; // index-only ALTER, or nothing column-shaped
            }

            $bare = resolve_alter_table($src, $offset, $t[1][0], $symbols, $varMap, $localMethods);
            if ($bare === null) {
                // A DROP on an unresolved table cannot ADD anything, so it
                // cannot hide a column; only an unresolved ADD is a gap.
                if ($adds !== [] || $unresolved) {
                    $res['problems'][] = "ALTER TABLE at {$where} adds a column but its table could not be resolved";
                }
                continue;
            }
            if ($unresolved) {
                $res['problems'][] = "ALTER TABLE wp_{$bare} at {$where} adds a column named by a variable that could not be resolved to a literal column list";
            }
            foreach ($adds as $col) {
                $res['ops'][$bare][] = ['op' => 'add', 'col' => $col, 'where' => $where];
            }
            foreach ($drops as $col) {
                $res['ops'][$bare][] = ['op' => 'drop', 'col' => $col, 'where' => $where];
            }
            foreach ($renames as [$from, $to]) {
                $res['ops'][$bare][] = ['op' => 'rename', 'col' => $from, 'to' => $to, 'where' => $where];
            }
        }
    }
    return $res;
}

/**
 * @return array{cols: array<string, array<string,string>>, problems: list<string>}
 *         cols: bare table => column => "file:line" evidence
 */
function collect_declared_columns(array $files, array $symbols, string $relBase): array {
    $funcArrays = function_return_arrays($files);
    $creates = $like = $ops = [];
    $problems = [];
    foreach ($files as $file) {
        if (is_test_or_script($file)) {
            continue;
        }
        $r = scan_declared_columns_in_file($file, $symbols, $funcArrays, $relBase);
        foreach ($r['creates'] as $bare => $list) {
            foreach ($list as $c) {
                $creates[$bare][] = $c;
            }
        }
        $like += $r['like'];
        foreach ($r['ops'] as $bare => $list) {
            foreach ($list as $o) {
                $ops[$bare][] = $o;
            }
        }
        array_push($problems, ...$r['problems']);
    }

    $cols = [];
    foreach ($creates as $bare => $list) {
        foreach ($list[0]['cols'] as $col => $line) {
            $cols[$bare][$col] = "{$list[0]['file']}:{$line}";
        }
        for ($k = 1; $k < count($list); $k++) {
            $a = array_keys($list[0]['cols']);
            $b = array_keys($list[$k]['cols']);
            sort($a);
            sort($b);
            if ($a !== $b) {
                $problems[] = "wp_{$bare} has two CREATE TABLE statements with DIFFERENT column sets ({$list[0]['file']} and {$list[$k]['file']})";
            }
        }
    }
    foreach ($ops as $bare => $list) {
        $created = $cols[$bare] ?? [];
        $added   = [];
        foreach ($list as $o) {
            if ($o['op'] === 'add') {
                $added[$o['col']] = true;
                $cols[$bare][$o['col']] = $cols[$bare][$o['col']] ?? "{$o['where']} (ALTER ADD)";
            }
        }
        foreach ($list as $o) {
            if ($o['op'] === 'drop') {
                // A migration that drops a column the shipped schema still
                // creates or adds is self-contradicting: dbDelta / the adder
                // re-creates it on the next schema pass.
                if (isset($created[$o['col']]) || isset($added[$o['col']])) {
                    $problems[] = "wp_{$bare}.{$o['col']} is dropped at {$o['where']} but the shipped schema still creates or adds it";
                }
                // Otherwise: dropping a column that is already gone — the
                // normal shape of a retired-column migration. No-op.
            } elseif ($o['op'] === 'rename' && isset($cols[$bare][$o['col']])) {
                unset($cols[$bare][$o['col']]);
                $cols[$bare][$o['to']] = "{$o['where']} (ALTER rename)";
            }
        }
    }
    foreach ($like as $bare => $src) {
        if ($src !== null && isset($cols[$src])) {
            $cols[$bare] = $cols[$src];
        } else {
            $problems[] = "wp_{$bare} is created LIKE a table whose columns could not be resolved";
        }
    }
    return ['cols' => $cols, 'problems' => $problems];
}

$declaredColumnScan = collect_declared_columns($declaredFiles, $SYMBOLS, $PLUGINS_DIR);
$declaredColumns    = $declaredColumnScan['cols'];

/*
|--------------------------------------------------------------------------
| (2) DOCUMENTED — parse the master-inventory table in database-schema.md
|--------------------------------------------------------------------------
| Row shape: | Table | ~rows | Purpose | Owning code | Status |
| We extract the bare table name + its Status, which must be EXACTLY one of
| DOC_STATUSES (whole cell, case-sensitive). Anything else is drift.
*/

/**
 * The only Status values the inventory may carry. Matched against the WHOLE
 * trimmed Status cell, case-sensitively — never by substring, so free text
 * (in that cell or any other) cannot influence classification.
 *
 *   Active  — declared in code, expected live.
 *   ORPHAN  — gone from the codebase; may linger live until dropped.
 *   RETIRED — actively dropped by a migration; no longer declared.
 *
 * ORPHAN and RETIRED are kept distinct: neither may still be declared in
 * code (CHECK 1c), but a RETIRED table may be, during an explicit
 * RETIREMENT_WINDOW entry; an ORPHAN one never.
 */
const DOC_STATUSES = ['Active', 'ORPHAN', 'RETIRED'];

/**
 * @param list<string> $problems  receives one message per malformed row
 *                                (unrecognised Status, duplicate table row)
 * @return array<string, array{status: string, raw: string}>  bare => meta
 */
function parse_documented(string $doc, array &$problems = []): array {
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
        $bare       = bare_name($tableCell);
        // Exact whole-cell match. The previous substring classifier
        // (`stripos(..., 'orphan')`) let any wording containing the word
        // count, and an unrecognised cell silently defaulted to Active.
        // Deny-by-default instead: an unknown Status is itself drift, and the
        // row is recorded as UNKNOWN so it is neither Active (1a) nor missing
        // (1b) — the problem is reported once, as what it is.
        if (in_array($statusCell, DOC_STATUSES, true)) {
            $status = $statusCell;
        } else {
            $status     = 'UNKNOWN';
            $problems[] = "'{$tableCell}' has unrecognised Status '{$statusCell}' — the Status cell must be exactly one of: "
                . implode(', ', DOC_STATUSES);
        }
        // A second row for the same table could carry a different Status and
        // mask the first (last one would win). Flag it rather than pick one.
        if (isset($out[$bare])) {
            $problems[] = "'{$tableCell}' appears more than once in the master inventory";
        }
        $out[$bare] = ['status' => $status, 'raw' => $tableCell];
    }
    return $out;
}

/** @var list<string> $inventoryProblems */
$inventoryProblems = [];
$documented = parse_documented($SCHEMA_DOC, $inventoryProblems);
$docActive  = array_filter($documented, static fn(array $m): bool => $m['status'] === 'Active');
$docOrphan  = array_filter($documented, static fn(array $m): bool => $m['status'] === 'ORPHAN');
$docRetired = array_filter($documented, static fn(array $m): bool => $m['status'] === 'RETIRED');

/*
|--------------------------------------------------------------------------
| (2b) DOCUMENTED COLUMNS — the per-table blocks under
|      "## Active tables — per-table detail"
|--------------------------------------------------------------------------
| One `#### wp_bcc_x` heading per block; `#### wp_bcc_a / wp_bcc_b` covers
| tables with an IDENTICAL column set (an archive created LIKE its source).
| A heading carrying `— RETIRED` is history and is not parsed.
|
| Column rows are top-level bullets `- name · type · null · key [— prose]`.
| Accepted variants, all in use in the doc:
|   - `a / b · type`                     several columns share one type
|   - `a · t; b · t`                     several columns on one bullet
|   - `a · t1 / b · t2`                  several columns, each with its type
|   - `name(K)`                          a key flag glued to the name
|   - `~~name~~ · …`                     retired IN PLACE: no longer used, but
|                                        still in the CREATE TABLE, so it IS
|                                        documented and must stay declared
|   - `… · OPTIONAL …`                   no schema install creates it (only a
|                                        hand-run script); exempt from
|                                        documented ⇒ declared, and must NOT
|                                        be declared by shipped schema code
| Not column rows: `- Indexes…`, `- *Column(s) dropped:* …` (history), and
| any bullet with no `name ·` lead (prose).
*/

/**
 * @param list<string> $problems
 * @return array<string, array{line: int, cols: array<string, array{line: int, optional: bool}>}>
 */
function parse_documented_columns(string $doc, array &$problems = []): array {
    $lines  = explode("\n", str_replace("\r\n", "\n", (string) file_get_contents($doc)));
    $in     = false;
    $cur    = [];
    $blocks = [];
    $ident  = '[a-z_][a-z0-9_]*';
    foreach ($lines as $i => $line) {
        $n = $i + 1;
        if (strpos($line, '## ') === 0) {
            $in  = strpos($line, '## Active tables') === 0;
            $cur = [];
            continue;
        }
        if (!$in) {
            continue;
        }
        if (strpos($line, '### ') === 0) {
            $cur = [];
            continue;
        }
        if (preg_match('/^#### (.*)$/', $line, $h)) {
            $cur = [];
            if (preg_match('/—\s*RETIRED\b/', $h[1])) {
                continue;
            }
            $names = preg_split('/\s+—\s+|\s+\(/', $h[1])[0];
            foreach (preg_split('/\s*\/\s*/', $names) as $nm) {
                $nm = trim($nm, " `");
                if (!preg_match('/^wp_bcc_[a-z0-9_]+$/', $nm)) {
                    continue;
                }
                $bare = bare_name($nm);
                if (isset($blocks[$bare])) {
                    $problems[] = "'{$nm}' has more than one column block (doc:{$blocks[$bare]['line']} and doc:{$n})";
                    continue;
                }
                $blocks[$bare] = ['line' => $n, 'cols' => []];
                $cur[] = $bare;
            }
            continue;
        }
        if ($cur === [] || !preg_match('/^- (.*)$/', $line, $b)) {
            continue;
        }
        $text = $b[1];
        if (preg_match('/^(Indexes\b|\*)/', $text)) {
            continue;
        }
        $text = preg_split('/\s+—\s+/', $text)[0];
        foreach (preg_split('/;\s*/', $text) as $seg) {
            $optional = preg_match('/·\s*OPTIONAL\b/', $seg) === 1;
            // Split on ' / ' and keep each piece's NAME: a piece is either a
            // bare name (sharing the type that follows) or `name · type…`.
            $pieces = preg_split('/\s+\/\s+/', $seg);
            $names  = [];
            $typed  = false;
            foreach ($pieces as $p) {
                $p = trim($p);
                if (!preg_match('/^(~~)?`?(' . $ident . ')`?(~~)?(?:\([A-Z]+\))?(\s+·\s+|$)/', $p, $m)) {
                    break; // the rest of the segment is type / key prose
                }
                if ($typed && $m[4] === '') {
                    break; // after `a · type`, only `b · type` continues the list
                }
                $names[] = strtolower($m[2]);
                if ($m[4] !== '') {
                    $typed = true;
                }
            }
            if (!$typed) {
                continue; // no `name ·` lead — prose, not a column row
            }
            foreach ($names as $col) {
                foreach ($cur as $t) {
                    $blocks[$t]['cols'][$col] = $blocks[$t]['cols'][$col] ?? ['line' => $n, 'optional' => $optional];
                }
            }
        }
    }
    return $blocks;
}

/** @var list<string> $docColumnProblems */
$docColumnProblems = [];
$docColumns = parse_documented_columns($SCHEMA_DOC, $docColumnProblems);

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
   . count($docActive) . " Active, " . count($docOrphan) . " ORPHAN, " . count($docRetired) . " RETIRED"
   . (count($documented) - count($docActive) - count($docOrphan) - count($docRetired) > 0
        ? ", " . (count($documented) - count($docActive) - count($docOrphan) - count($docRetired)) . " UNRECOGNISED"
        : '')
   . ")\n";
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

// 1b: every DECLARED table must appear in the doc (under any Status).
foreach ($declaredTables as $bare => $info) {
    if (!isset($documented[$bare])) {
        $msg = "declared table 'wp_{$bare}' is NOT in docs/database-schema.md (regenerate the doc) — declared in " . basename($info['file']);
        $c1[] = $msg;
        $failFindings[] = "[parity] {$msg}";
    }
}

// 1c: a documented-ORPHAN or documented-RETIRED table must NOT still be
// declared. Either label asserts the table is gone from the codebase; if a
// CREATE TABLE or TableRegistry accessor still declares it, doc and code
// disagree. Without this, relabelling a live table's Status is a static
// false-green: the row leaves the Active set, so 1a stops asking about it,
// and 1b is satisfied because the row still exists (issue #162).
//
// ORPHAN: no exception, ever.
//
// RETIRED: the same rule, with ONE sanctioned exception — the retirement
// window. A drop spans two repos: this repo's inventory row has to flip to
// RETIRED in a PRECEDING umbrella PR, because CI always reads bcc-trust
// `main` and the reverse order fails 1a the moment the declaration goes
// (docs/database-schema.md, "The retirement is a TWO-DEPLOY sequence").
// During that window the table is RETIRED and still declared, legitimately.
// It is tolerated ONLY when the table is named in RETIREMENT_WINDOW below,
// with a reason — a reviewed edit to this script, in the same diff as the
// relabel. Tolerating every RETIRED-but-declared table instead would make
// "relabel to RETIRED" the same bypass under a different word.
//
// Window entries are policed both ways, so they cannot outlive the window:
// an entry whose table is no longer declared (the drop landed — remove the
// entry) or whose row is not RETIRED fails.

/** @var array<string,string> bare table => reason (issue/PR + expected drop) */
const RETIREMENT_WINDOW = [
    // 'bcc_example' => 'Retiring in bcc-trust #NNN; declaration removed when that merges.',
];

foreach ($docOrphan + $docRetired as $bare => $meta) {
    if (!isset($declaredTables[$bare])) {
        continue;
    }
    if ($meta['status'] === 'RETIRED' && isset(RETIREMENT_WINDOW[$bare])) {
        $infoFindings[] = "[retirement-window] RETIRED '{$meta['raw']}' still declared in "
            . basename($declaredTables[$bare]['file']) . ' — tolerated: ' . RETIREMENT_WINDOW[$bare];
        continue;
    }
    $msg = "documented-{$meta['status']} '{$meta['raw']}' is STILL declared in code — declared in "
        . basename($declaredTables[$bare]['file'])
        . " (the {$meta['status']} label asserts the table is gone from the codebase"
        . ($meta['status'] === 'RETIRED' ? '; a deliberate retirement window needs a RETIREMENT_WINDOW entry' : '')
        . ')';
    $c1[] = $msg;
    $failFindings[] = "[parity] {$msg}";
}

foreach (RETIREMENT_WINDOW as $bare => $reason) {
    $status = $documented[$bare]['status'] ?? null;
    if ($status !== 'RETIRED') {
        $msg = "RETIREMENT_WINDOW entry 'wp_{$bare}' is stale — its inventory Status is "
            . ($status === null ? 'absent' : "'{$status}'") . ", not RETIRED (remove the entry)";
    } elseif (!isset($declaredTables[$bare])) {
        $msg = "RETIREMENT_WINDOW entry 'wp_{$bare}' is stale — the table is no longer declared, so the window has closed (remove the entry)";
    } else {
        continue;
    }
    $c1[] = $msg;
    $failFindings[] = "[parity] {$msg}";
}

// 1d: every inventory row must carry an exact, recognised Status and appear
// once (see parse_documented()).
foreach ($inventoryProblems as $p) {
    $c1[] = $p;
    $failFindings[] = "[inventory] {$p}";
}

if ($c1 === []) {
    echo "  OK — declared and documented-Active table sets agree; no ORPHAN/RETIRED table is still declared.\n";
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
    foreach ($docOrphan + $docRetired as $bare => $meta) {
        if (isset($liveTables[$bare])) {
            $msg = "{$meta['status']} '{$meta['raw']}' still present live (expected until its guarded drop runs)";
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

/* ---- CHECK 3: static column parity (DECLARED vs DOCUMENTED) ----------- */
//
// Issue #132. For every documented-Active, declared table:
//   3a  it has a column block in "## Active tables — per-table detail";
//   3b  every DECLARED column is documented;
//   3c  every DOCUMENTED column is declared (an OPTIONAL row is exempt —
//       only a hand-run script adds it);
//   3d  an OPTIONAL row is NOT declared by shipped schema code (if it is,
//       it is a real column and the marker is wrong);
//   3e  its column set could be read at all — a declared table with no
//       parseable CREATE TABLE is a gap, not a pass;
// plus every coverage problem the two parsers raised (an unresolvable
// column-adding ALTER, conflicting CREATE TABLEs, a duplicate block).
// RETIRED/ORPHAN tables are skipped: their blocks are kept as history.

echo "\n--- CHECK 3: static column parity (DECLARED vs DOCUMENTED) ---\n";

$c3 = [];
$c3Tables = 0;
$c3Columns = 0;
foreach ($docActive as $bare => $meta) {
    if (!isset($declaredTables[$bare])) {
        continue; // already a 1a failure
    }
    $raw = $meta['raw'];
    $c3Tables++;
    $c3Columns += count($declaredColumns[$bare] ?? []);
    if (!isset($declaredColumns[$bare]) || $declaredColumns[$bare] === []) {
        $c3[] = "'{$raw}' is declared (" . basename($declaredTables[$bare]['file'])
            . ") but no column set could be read from shipped schema code (no parseable CREATE TABLE outside tests/ and scripts/)";
        continue;
    }
    if (!isset($docColumns[$bare]) || $docColumns[$bare]['cols'] === []) {
        $c3[] = "'{$raw}' has no column block in docs/database-schema.md (add `#### {$raw}` with its columns under \"## Active tables — per-table detail\")";
        continue;
    }
    $doc = $docColumns[$bare]['cols'];
    foreach ($declaredColumns[$bare] as $col => $where) {
        if (!isset($doc[$col])) {
            $c3[] = "declared column '{$raw}.{$col}' is NOT documented — declared at {$where}";
        } elseif ($doc[$col]['optional']) {
            $c3[] = "'{$raw}.{$col}' is documented OPTIONAL (doc:{$doc[$col]['line']}) but shipped schema code declares it at {$where} — drop the OPTIONAL marker";
        }
    }
    foreach ($doc as $col => $d) {
        if (!$d['optional'] && !isset($declaredColumns[$bare][$col])) {
            $c3[] = "documented column '{$raw}.{$col}' (doc:{$d['line']}) is NOT declared by any CREATE TABLE or schema migration";
        }
    }
}
// A block for a table the inventory does not know (a typo'd heading, or a
// table removed from the inventory but not from the detail section) is
// compared against nothing — so the real table's block would read as
// missing only by luck. Name it. `— RETIRED` headings were never parsed.
foreach ($docColumns as $bare => $blk) {
    if (!isset($documented[$bare])) {
        $c3[] = "column block 'wp_{$bare}' (doc:{$blk['line']}) is for a table that is not in the master inventory (typo'd heading? a retired table's block needs `— RETIRED` in its heading)";
    }
}
foreach (array_merge($declaredColumnScan['problems'], $docColumnProblems) as $p) {
    $c3[] = $p;
}

if ($c3 === []) {
    echo "  OK — {$c3Tables} documented-Active tables / {$c3Columns} declared columns compared; every table has a column block and its columns match in both directions.\n";
} else {
    foreach ($c3 as $f) {
        echo "  DRIFT: {$f}\n";
        $failFindings[] = "[columns] {$f}";
    }
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
