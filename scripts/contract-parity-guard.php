<?php
/**
 * Contract-vs-code parity guard.
 *
 * Verifies parity in BOTH directions: every endpoint declared in
 * docs/api-contract-v1.md is actually registered in PHP via
 * register_rest_route(), and every in-scope registered route is either
 * documented in §4 or explicitly allowlisted in EXEMPT_INTERNAL.
 *
 * Catches the failure mode that surfaced 2026-05-26 in reverse: the
 * contract claimed `/wallets/project/{post_id}` had envelope drift,
 * but no live verification was ever performed and the claim was
 * wrong. A static parity probe (this file) + the api-contract-check.sh
 * deep-tier checker would have caught both directions of that drift
 * earlier.
 *
 * Sibling to scripts/subsystem-count-guard.php (different surface,
 * same shape: PHP token-walks code + regex-scans docs, diffs).
 *
 * Exit 0 = contract and code agree in both directions.
 * Exit 1 = drift detected. Any of:
 *            - a contract endpoint with no register_rest_route()  (clients 404)
 *            - a registered in-scope route undocumented and not allowlisted
 *            - a stale EXEMPT_INTERNAL entry (route gone / since documented)
 *
 * The latter two were WARN-only until 2026-07-24, while the 2026-05-26
 * backlog was cleared. It reached zero, so they now block — an
 * undocumented live route is a reachable surface no contract review has
 * seen, and a stale exemption silently pre-authorises whatever reappears
 * under its key.
 *
 * Run from repo root:
 *   php scripts/contract-parity-guard.php
 *
 * Scope deliberately tight:
 *   - Only the bcc/v1 + bcc-trust/v1 namespaces are compared.
 *   - Path-shape comparison only; HTTP method handled best-effort.
 *   - Live response-shape validation is the job of api-contract-check.sh
 *     (deep-tier). This guard is the cheap static gate.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from CLI.\n");
    exit(1);
}

$REPO_ROOT = realpath(__DIR__ . '/..');
if ($REPO_ROOT === false) {
    fwrite(STDERR, "Cannot resolve repo root.\n");
    exit(1);
}

$CONTRACT_PATH = $REPO_ROOT . '/docs/api-contract-v1.md';
$PLUGIN_ROOTS = [
    $REPO_ROOT . '/app/public/wp-content/plugins/bcc-core',
    $REPO_ROOT . '/app/public/wp-content/plugins/bcc-search',
    $REPO_ROOT . '/app/public/wp-content/plugins/bcc-trust',
];

if (!is_readable($CONTRACT_PATH)) {
    fwrite(STDERR, "Missing: {$CONTRACT_PATH}\n");
    exit(2);
}

/*
|--------------------------------------------------------------------------
| NAMESPACES IN SCOPE
|--------------------------------------------------------------------------
| Only routes under these namespaces are flow through Envelope::wrap()
| and constitute the public contract surface. Others (wp/v2/*,
| akismet/*, internal admin AJAX) are intentionally out of scope.
*/

const IN_SCOPE_NAMESPACES = ['bcc/v1', 'bcc-trust/v1'];

/*
|--------------------------------------------------------------------------
| EXEMPT INTERNAL ROUTES
|--------------------------------------------------------------------------
| Routes registered in-scope but intentionally NOT part of the public
| api-contract-v1.md surface: operator/admin endpoints (manage_options /
| admin_permission_check) and machine endpoints (shared-secret webhooks,
| OAuth browser-redirect callbacks, liveness probes). Each was classified
| and its permission posture verified in docs/archive/route-audit-2026-06-10.md.
|
| Keys are 'METHOD /namespace/path' in the SAME normalized form this guard
| emits (path params as :name). Each entry carries a one-line reason.
|
| Anything registered, undocumented, AND not on this list is a real §γ
| contract gap — that is the signal the allowlist preserves. Keep it tight:
| do NOT add public/member-facing routes here to silence the WARN; document
| those in §4 instead. Stale entries (route deleted, or since documented)
| are reported below so the list stays honest.
*/
const EXEMPT_INTERNAL = [
    // — Admin (manage_options / admin_permission_check) —
    'GET /bcc/v1/system/health'                 => 'admin: health aggregate',
    'GET /bcc-trust/v1/health/read-model'       => 'admin: read-model coverage/drift',
    // The eight bcc-trust/v1 fraud/stats + analyze-user entries were
    // pruned 2026-07-21 with their routes (admin-audit dead-endpoint
    // cleanup) — the wp-admin fraud surface renders server-side.
    'POST /bcc/v1/admin/digest/run-now'         => 'admin: digest trigger (in-handler manage_options)',
    'GET /bcc/v1/admin/reports'                 => 'admin: moderation queue (in-handler manage_options)',
    'POST /bcc/v1/admin/reports/:id/resolve'    => 'admin: moderation resolve',
    'POST /bcc/v1/admin/reports/undo'           => 'admin: moderation undo (token-gated)',
    'GET /bcc/v1/disputes/health'               => 'admin: dispute-system health',
    'POST /bcc/v1/disputes/:id/resolve'         => 'admin: force-resolve dispute',
    'POST /bcc/v1/onchain/:page_id/refresh'     => 'admin: force on-chain re-fetch',

    // — Machine (shared-secret / OAuth callback / liveness) —
    'GET /bcc/v1/system/ping'                   => 'machine: public liveness probe (no sensitive data)',
    'GET /bcc/v1/digest/unsubscribe'            => 'machine: HMAC-token email unsubscribe link',
    'GET /bcc-trust/v1/github/callback'         => 'machine: GitHub OAuth redirect (CSRF state)',
    'GET /bcc-trust/v1/x/callback'              => 'machine: X OAuth redirect (CSRF state)',
    'POST /bcc/v1/onchain/helius/webhook'       => 'machine: Helius webhook (hash_equals secret)',
    'POST /bcc/v1/internal/indexer/tick'        => 'machine: Vercel cron (hash_equals secret)',
    'POST /bcc/v1/auth/oauth'                   => 'machine: NextAuth SSO bridge (X-Bcc-Oauth-Secret, hash_equals, fail-closed)',
];

/*
|--------------------------------------------------------------------------
| CONTRACT PARSER — extract declared endpoints from api-contract-v1.md
|--------------------------------------------------------------------------
| Endpoint declarations look like:
|   #### `GET /bcc/v1/auth/wallet-nonce`
|   #### `POST /bcc/v1/wallets/{id}`
|   #### `GET /bcc/v1/users/:handle`
|   #### `GET /bcc/v1/users/mention-search` (v1.5)      ← suffix tolerated
|
| Path params come in two styles: `{name}` and `:name`. Both are normalized
| to `:name` for comparison.
*/

/**
 * @return list<array{method: string, path: string, raw: string, lineno: int}>
 */
function parse_contract_endpoints(string $path): array {
    $contents = (string) file_get_contents($path);
    $lines    = explode("\n", $contents);
    $found    = [];

    // Match: #### `METHOD /path` (path may contain `{x}`, `:x`, or literal segments)
    // The trailing backtick must close before optional descriptive text (e.g. "(v1.5)").
    //
    // An optional section-label may precede the backtick — the §J.* endpoints are
    // headed `#### §J.5 \`GET /bcc/v1/me/reliability\``. The `(?:[^`]*\s+)?` group
    // consumes such a label (anything up to the opening backtick) so those headers
    // are recognized as real documentation, not false-flagged as undocumented.
    $pattern = '/^####\s+(?:[^`]*\s+)?`(GET|POST|PUT|PATCH|DELETE)\s+(\/\S+?)`/';

    foreach ($lines as $idx => $line) {
        if (preg_match($pattern, $line, $m) === 1) {
            $method = strtoupper($m[1]);
            $rawPath = $m[2];
            $normPath = normalize_path($rawPath);
            $found[] = [
                'method' => $method,
                'path'   => $normPath,
                'raw'    => $rawPath,
                'lineno' => $idx + 1,
            ];
        }
    }
    return $found;
}

/**
 * Normalize a path to canonical comparison form. Both contract styles
 * ({name} and :name) and the code's regex form (?P<name>regex) collapse
 * to `:name`.
 */
function normalize_path(string $path): string {
    // (?P<name>regex) → :name
    $out = preg_replace('/\(\?P<([A-Za-z_][A-Za-z0-9_]*)>[^)]*\)/', ':$1', $path);
    // {name} → :name
    $out = preg_replace('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', ':$1', (string) $out);
    // Trim trailing slash so "/foo/" and "/foo" match.
    return rtrim((string) $out, '/');
}

/*
|--------------------------------------------------------------------------
| CODE PARSER — extract register_rest_route() calls from PHP
|--------------------------------------------------------------------------
| Token-walk to find:
|   register_rest_route('namespace', '/path', [...])
|   register_rest_route(self::NAMESPACE, self::ROUTE, [...])
|   register_rest_route(self::NS, '/users/(?P<slug>' . OtherClass::HANDLE_PATTERN . ')/groups', [...])
|
| Class constants in the same file are resolved via a same-file
| `const NAME = '...'` lookup. Cross-file class constants (`OtherClass::CONST`)
| are resolved via a one-pass-walk-of-all-files map built up front.
| Anything still unresolved emits a warning.
*/

/**
 * Yield every in-scope PHP file under `$pluginRoots`, skipping vendored
 * dependencies. Shared by parse_php_routes() and
 * collect_all_class_constants() so both walk exactly the same file set.
 *
 * @param list<string> $pluginRoots
 * @return iterable<string>
 */
function iterate_plugin_php_files(array $pluginRoots): iterable {
    foreach ($pluginRoots as $root) {
        if (!is_dir($root)) {
            continue;
        }
        $iter = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
                static function ($current) {
                    $path = (string) $current->getPathname();
                    if (strpos($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) !== false) {
                        return false;
                    }
                    if (strpos($path, DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR) !== false) {
                        return false;
                    }
                    // Test code is never a production route surface. Test
                    // stubs may even *define* a fake register_rest_route()
                    // (fake-at-FQN), which would otherwise register as an
                    // unresolvable "site." Only real endpoints ship routes.
                    if (strpos($path, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR) !== false) {
                        return false;
                    }
                    return true;
                }
            )
        );
        foreach ($iter as $f) {
            $fpath = (string) $f->getPathname();
            if (substr($fpath, -4) !== '.php') {
                continue;
            }
            yield $fpath;
        }
    }
}

/**
 * @param list<string> $pluginRoots
 * @param array<string, array<string, string>> $crossFileConstants  ClassName => [CONST => value]
 * @return list<array{file: string, lineno: int, namespace: string, path: string, methods: list<string>, unresolved: bool}>
 */
function parse_php_routes(array $pluginRoots, array $crossFileConstants): array {
    $routes = [];
    foreach (iterate_plugin_php_files($pluginRoots) as $fpath) {
        foreach (extract_routes_from_file($fpath, $crossFileConstants) as $r) {
            $routes[] = $r;
        }
    }
    return $routes;
}

/**
 * One-pass walk of every plugin PHP file collecting `ClassName::CONST = 'literal'`
 * declarations. Used to resolve cross-file constant references in
 * `register_rest_route()` paths (e.g. `OtherEndpoint::HANDLE_PATTERN`).
 *
 * @param list<string> $pluginRoots
 * @return array<string, array<string, string>>  ClassName => [CONST => value]
 */
function collect_all_class_constants(array $pluginRoots): array {
    $map = [];
    foreach (iterate_plugin_php_files($pluginRoots) as $fpath) {
        foreach (extract_class_constants_from_file($fpath) as $className => $constMap) {
            if (!isset($map[$className])) {
                $map[$className] = [];
            }
            // Last writer wins on duplicate ClassName across files; rare in
            // practice (different namespaces would normally use different
            // short names too).
            foreach ($constMap as $name => $value) {
                $map[$className][$name] = $value;
            }
        }
    }
    return $map;
}

/**
 * Extract `ClassName => [CONST => value]` from a single file. Tracks
 * class scope via brace counting after the `class X` declaration, so
 * top-level `const` statements outside classes (rare) are ignored.
 *
 * @return array<string, array<string, string>>
 */
function extract_class_constants_from_file(string $fpath): array {
    $src = (string) file_get_contents($fpath);
    if (strpos($src, 'class ') === false) {
        return [];
    }
    $tokens = token_get_all($src);
    $n = count($tokens);

    $identifierTokens = [
        T_STRING, T_NAMESPACE, T_CLASS, T_INTERFACE, T_TRAIT, T_FUNCTION,
        T_PRINT, T_ECHO, T_LIST, T_ARRAY,
    ];

    $result = [];
    $i = 0;
    while ($i < $n) {
        $t = $tokens[$i];
        if (is_array($t) && $t[0] === T_CLASS) {
            // Class declaration: T_CLASS, ws, T_STRING (name), ..., {
            $j = skip_ws($tokens, $i + 1, $n);
            if ($j >= $n || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
                $i++;
                continue;
            }
            $className = $tokens[$j][1];
            // Find the opening brace of the class body.
            $k = $j + 1;
            while ($k < $n && $tokens[$k] !== '{') {
                $k++;
            }
            if ($k >= $n) {
                $i++;
                continue;
            }
            // Walk class body, collecting depth-1 const declarations.
            $depth = 1;
            $k++;
            while ($k < $n && $depth > 0) {
                $tt = $tokens[$k];
                if ($tt === '{') {
                    $depth++;
                } elseif ($tt === '}') {
                    $depth--;
                } elseif (is_array($tt) && $tt[0] === T_CONST && $depth === 1) {
                    $m = skip_ws($tokens, $k + 1, $n);
                    if ($m < $n && is_array($tokens[$m]) && in_array($tokens[$m][0], $identifierTokens, true)) {
                        $constName = $tokens[$m][1];
                        $p = skip_ws($tokens, $m + 1, $n);
                        if ($p < $n && $tokens[$p] === '=') {
                            $q = skip_ws($tokens, $p + 1, $n);
                            if ($q < $n && is_array($tokens[$q]) && $tokens[$q][0] === T_CONSTANT_ENCAPSED_STRING) {
                                $result[$className][$constName] = trim($tokens[$q][1], "'\"");
                            }
                        }
                    }
                }
                $k++;
            }
            $i = $k;
            continue;
        }
        $i++;
    }
    return $result;
}

/**
 * @param array<string, array<string, string>> $crossFileConstants
 * @return list<array{file: string, lineno: int, namespace: string, path: string, methods: list<string>, unresolved: bool}>
 */
function extract_routes_from_file(string $fpath, array $crossFileConstants): array {
    $src = (string) file_get_contents($fpath);
    if (strpos($src, 'register_rest_route') === false) {
        return [];
    }

    $tokens = token_get_all($src);
    $constants = same_file_string_constants($tokens);

    $found = [];
    $n = count($tokens);
    $i = 0;
    while ($i < $n) {
        $t = $tokens[$i];
        if (is_array($t) && $t[0] === T_STRING && $t[1] === 'register_rest_route') {
            $j = skip_ws($tokens, $i + 1, $n);
            if ($j < $n && $tokens[$j] === '(') {
                $args = parse_call_args($tokens, $j + 1, $n);
                if (count($args) >= 2) {
                    $namespace = resolve_string_arg($args[0], $constants, $crossFileConstants);
                    $path      = resolve_string_arg($args[1], $constants, $crossFileConstants);
                    $methods   = isset($args[2])
                        ? resolve_methods_arg($args[2], $constants)
                        : ['*'];
                    $unresolved = ($namespace === null) || ($path === null);
                    $found[] = [
                        'file'       => $fpath,
                        'lineno'     => is_array($t) ? (int) $t[2] : 0,
                        'namespace'  => (string) ($namespace ?? '?'),
                        'path'       => (string) ($path ?? '?'),
                        'methods'    => $methods,
                        'unresolved' => $unresolved,
                    ];
                }
            }
        }
        $i++;
    }
    return $found;
}

function skip_ws(array $tokens, int $i, int $n): int {
    while ($i < $n && is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
        $i++;
    }
    return $i;
}

/**
 * Parse comma-separated argument expressions inside (...). Returns each
 * arg as a sub-array of tokens (whitespace/comments preserved within;
 * caller decides how to interpret).
 *
 * @return list<list<mixed>>
 */
function parse_call_args(array $tokens, int $i, int $n): array {
    $args = [];
    $current = [];
    $depth = 1; // we've consumed the opening (
    while ($i < $n && $depth > 0) {
        $t = $tokens[$i];
        if ($t === '(' || $t === '[' || $t === '{') {
            $depth++;
            $current[] = $t;
        } elseif ($t === ')' || $t === ']' || $t === '}') {
            $depth--;
            if ($depth === 0) {
                if ($current !== []) {
                    $args[] = $current;
                }
                return $args;
            }
            $current[] = $t;
        } elseif ($depth === 1 && $t === ',') {
            $args[] = $current;
            $current = [];
        } else {
            $current[] = $t;
        }
        $i++;
    }
    return $args;
}

/**
 * Walk tokens once to extract `const NAME = 'literal';` and
 * `private/public/protected const NAME = 'literal';` declarations.
 *
 * @return array<string, string>
 */
function same_file_string_constants(array $tokens): array {
    // Constant names can be PHP reserved words (e.g. `NAMESPACE`), in
    // which case the tokenizer returns T_NAMESPACE / T_CLASS / etc.
    // instead of T_STRING. Treat any of these as a valid identifier.
    $identifierTokens = [
        T_STRING, T_NAMESPACE, T_CLASS, T_INTERFACE, T_TRAIT, T_FUNCTION,
        T_PRINT, T_ECHO, T_LIST, T_ARRAY,
    ];

    $consts = [];
    $n = count($tokens);
    $i = 0;
    while ($i < $n) {
        $t = $tokens[$i];
        if (is_array($t) && $t[0] === T_CONST) {
            // const NAME = 'value';
            $j = skip_ws($tokens, $i + 1, $n);
            if ($j < $n && is_array($tokens[$j]) && in_array($tokens[$j][0], $identifierTokens, true)) {
                $name = $tokens[$j][1];
                $k = skip_ws($tokens, $j + 1, $n);
                if ($k < $n && $tokens[$k] === '=') {
                    $m = skip_ws($tokens, $k + 1, $n);
                    if ($m < $n && is_array($tokens[$m]) && $tokens[$m][0] === T_CONSTANT_ENCAPSED_STRING) {
                        $consts[$name] = trim($tokens[$m][1], "'\"");
                    }
                }
            }
        }
        $i++;
    }
    return $consts;
}

/**
 * Resolve an argument expression to a string, handling:
 *   - 'literal'
 *   - self::CONST       (same-file constant)
 *   - OtherClass::CONST (cross-file class constant, via $crossFileConstants)
 *   - 'a' . 'b' . self::CONST . OtherClass::CONST . 'c'  (concatenation)
 *
 * Returns null when any sub-part can't be resolved (e.g. a $variable
 * or a constant the parser couldn't find). Partial resolution is
 * intentionally NOT done — a half-resolved path is worse than an
 * honest "unresolved".
 *
 * @param list<mixed> $argTokens
 * @param array<string, string> $constants  same-file `const NAME = '...'` declarations
 * @param array<string, array<string, string>> $crossFileConstants  ClassName => [CONST => value]
 */
function resolve_string_arg(array $argTokens, array $constants, array $crossFileConstants = []): ?string {
    $parts      = [];
    $unresolved = false;
    $n          = count($argTokens);
    $i          = 0;

    while ($i < $n) {
        $t = $argTokens[$i];

        // Skip trivia.
        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $i++;
            continue;
        }
        // Skip concatenation operator.
        if ($t === '.') {
            $i++;
            continue;
        }
        // String literal.
        if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
            $parts[] = trim($t[1], "'\"");
            $i++;
            continue;
        }
        // self / static / ClassName (followed by :: CONST).
        // T_STATIC is the token for `static`; T_STRING covers `self`,
        // class names, and most other identifiers; reserved-word constant
        // names like NAMESPACE come back as T_NAMESPACE etc.
        $identifierTokens = [
            T_STRING, T_NAMESPACE, T_CLASS, T_INTERFACE, T_TRAIT, T_FUNCTION,
            T_PRINT, T_ECHO, T_LIST, T_ARRAY, T_STATIC,
        ];
        if (is_array($t) && in_array($t[0], $identifierTokens, true)) {
            // Look ahead for ::IDENT.
            $j = $i + 1;
            while ($j < $n && is_array($argTokens[$j]) && in_array($argTokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $j++;
            }
            if ($j < $n && is_array($argTokens[$j]) && $argTokens[$j][0] === T_DOUBLE_COLON) {
                $k = $j + 1;
                while ($k < $n && is_array($argTokens[$k]) && in_array($argTokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $k++;
                }
                if ($k < $n && is_array($argTokens[$k]) && in_array($argTokens[$k][0], $identifierTokens, true)) {
                    $qualifier = $t[1];           // e.g. 'self', 'static', or a class name
                    $constName = $argTokens[$k][1];
                    $resolved  = null;

                    if ($qualifier === 'self' || $qualifier === 'static') {
                        // Same-file lookup.
                        $resolved = $constants[$constName] ?? null;
                    } else {
                        // Cross-file class lookup. Fall back to same-file
                        // if the explicit class match misses — covers the
                        // edge case where someone writes `ThisClass::FOO`
                        // referring to the current file's class.
                        $resolved = $crossFileConstants[$qualifier][$constName]
                            ?? $constants[$constName]
                            ?? null;
                    }

                    if ($resolved !== null) {
                        $parts[] = $resolved;
                    } else {
                        $unresolved = true;
                    }
                    $i = $k + 1;
                    continue;
                }
            }
            // Bare identifier not followed by :: — could itself be a const lookup.
            if (isset($constants[$t[1]])) {
                $parts[] = $constants[$t[1]];
            } else {
                $unresolved = true;
            }
            $i++;
            continue;
        }
        // Variable / function call / anything else — bail.
        if (is_array($t) && $t[0] === T_VARIABLE) {
            $unresolved = true;
            $i++;
            continue;
        }
        // Unknown token shape (e.g. `[`, `]`, `(` inside the arg) — bail.
        $unresolved = true;
        $i++;
    }

    if ($unresolved || $parts === []) {
        return null;
    }
    return implode('', $parts);
}

/**
 * @param list<mixed> $argTokens
 * @param array<string, string> $constants
 * @return list<string>  methods (GET / POST / etc.). Empty list = unknown.
 */
function resolve_methods_arg(array $argTokens, array $constants): array {
    // Walk the entire third-arg token stream collecting EVERY
    // `'methods' => XYZ` occurrence. Multi-config routes pass an array
    // of route configs each with their own `methods` key; a single-
    // config route has just one. Union the results.
    $tokenStream = $argTokens;
    $methodsList = [];
    $n = count($tokenStream);
    $i = 0;

    while ($i < $n) {
        $t = $tokenStream[$i];
        if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING && trim($t[1], "'\"") === 'methods') {
            // Skip whitespace + '=>' + collect value tokens.
            $j = $i + 1;
            while ($j < $n) {
                if (is_array($tokenStream[$j]) && in_array($tokenStream[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $j++;
                    continue;
                }
                if (is_array($tokenStream[$j]) && $tokenStream[$j][0] === T_DOUBLE_ARROW) {
                    $j++;
                    continue;
                }
                break;
            }
            // Collect value tokens until next comma at depth 0.
            $value = [];
            $depth = 0;
            while ($j < $n) {
                $tt = $tokenStream[$j];
                if ($tt === '[' || $tt === '(' || $tt === '{') $depth++;
                elseif ($tt === ']' || $tt === ')' || $tt === '}') { if ($depth === 0) break; $depth--; }
                elseif ($depth === 0 && $tt === ',') break;
                $value[] = $tt;
                $j++;
            }
            foreach (interpret_methods_value($value) as $m) {
                $methodsList[] = $m;
            }
            $i = $j;
            continue;
        }
        $i++;
    }

    return array_values(array_unique($methodsList));
}

/**
 * Interpret the value side of `'methods' => XYZ`.
 * Accepts: string literal, WP_REST_Server::CONSTANT, array.
 *
 * `$constants` is not consulted today — methods come from string
 * literals or well-known WP_REST_Server constants. Kept off the
 * signature to avoid lying about a dependency the function doesn't
 * have.
 *
 * @param list<mixed> $tokens
 * @return list<string>
 */
function interpret_methods_value(array $tokens): array {
    $methods = [];
    $i = 0;
    $n = count($tokens);
    while ($i < $n) {
        $t = $tokens[$i];
        if (is_array($t)) {
            if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literal = trim($t[1], "'\"");
                // 'POST, PUT, PATCH' style
                foreach (explode(',', $literal) as $m) {
                    $m = trim($m);
                    if ($m !== '') $methods[] = strtoupper($m);
                }
            } elseif ($t[0] === T_STRING) {
                $name = $t[1];
                // WP_REST_Server::READABLE etc.
                $mapped = wp_rest_method_constant_to_methods($name);
                if ($mapped !== []) {
                    foreach ($mapped as $m) $methods[] = $m;
                }
            }
        }
        $i++;
    }
    return array_values(array_unique($methods));
}

/**
 * @return list<string>
 */
function wp_rest_method_constant_to_methods(string $token): array {
    switch ($token) {
        case 'READABLE':  return ['GET'];
        case 'CREATABLE': return ['POST'];
        case 'EDITABLE':  return ['POST', 'PUT', 'PATCH'];
        case 'DELETABLE': return ['DELETE'];
        case 'ALLMETHODS': return ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
    }
    return [];
}

/*
|--------------------------------------------------------------------------
| COMPARE
|--------------------------------------------------------------------------
*/

$contractEndpoints   = parse_contract_endpoints($CONTRACT_PATH);
$crossFileConstants  = collect_all_class_constants($PLUGIN_ROOTS);
$phpRoutes           = parse_php_routes($PLUGIN_ROOTS, $crossFileConstants);

// Build a set of code-registered endpoints in scope: { (method, full-path) }
// where full-path = /{namespace}/{path-normalized}, restricted to
// IN_SCOPE_NAMESPACES.
$codeSet = [];
$unresolved = [];
foreach ($phpRoutes as $r) {
    if ($r['unresolved']) {
        $unresolved[] = $r;
        continue;
    }
    if (!in_array($r['namespace'], IN_SCOPE_NAMESPACES, true)) {
        continue;
    }
    $fullPath = '/' . $r['namespace'] . normalize_path($r['path']);
    $fullPath = rtrim($fullPath, '/');
    foreach ($r['methods'] !== [] ? $r['methods'] : ['*'] as $m) {
        $key = $m . ' ' . $fullPath;
        $codeSet[$key] = $r;
    }
}

// Match each contract endpoint.
$missing = [];
foreach ($contractEndpoints as $ce) {
    $key = $ce['method'] . ' ' . $ce['path'];
    $altKey = '* ' . $ce['path']; // catch-all-methods registrations
    if (!isset($codeSet[$key]) && !isset($codeSet[$altKey])) {
        // Try cross-method match: maybe code registers EDITABLE (POST/PUT/PATCH)
        // but the contract only specified POST — already handled above by
        // exploding methods.
        $missing[] = $ce;
    }
}

// Contract-extra warnings: code endpoints not declared in contract.
$contractPathSet = [];
foreach ($contractEndpoints as $ce) {
    $contractPathSet[$ce['method'] . ' ' . $ce['path']] = true;
}
$undocumented = [];
$exemptMatched = [];
foreach ($codeSet as $key => $r) {
    // Strip method prefix
    if (!isset($contractPathSet[$key])) {
        // Look for any-method contract match
        [$m, $p] = explode(' ', $key, 2);
        $anyMethodMatch = false;
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $candidate) {
            if (isset($contractPathSet[$candidate . ' ' . $p])) {
                $anyMethodMatch = true;
                break;
            }
        }
        if (!$anyMethodMatch) {
            // Intentionally-internal (admin/machine) routes are allowlisted
            // and don't belong in the public contract. Everything else is a
            // real §γ gap.
            if (array_key_exists($key, EXEMPT_INTERNAL)) {
                $exemptMatched[$key] = true;
            } else {
                $undocumented[] = $key;
            }
        }
    }
}

// Allowlist hygiene: an EXEMPT_INTERNAL entry is stale if its route is no
// longer registered (renamed/deleted) or has since been documented in the
// contract (redundant). Either way the entry should be pruned so the list
// keeps meaning what it says.
$staleExempt = [];
foreach (EXEMPT_INTERNAL as $exemptKey => $_reason) {
    $registered = isset($codeSet[$exemptKey]);
    $documented = isset($contractPathSet[$exemptKey]);
    if (!$registered) {
        $staleExempt[$exemptKey] = 'no longer registered — remove from allowlist';
    } elseif ($documented) {
        $staleExempt[$exemptKey] = 'now documented in §4 — remove from allowlist';
    }
}

/*
|--------------------------------------------------------------------------
| REPORT
|--------------------------------------------------------------------------
*/

echo "Contract endpoints parsed: " . count($contractEndpoints) . "\n";
echo "Code-registered routes parsed: " . count($phpRoutes) . " (in-scope: " . count($codeSet) . ")\n";
if ($unresolved !== []) {
    echo "Unresolved (could not extract namespace/path from PHP): " . count($unresolved) . "\n";
}
echo "\n";

$exit = 0;

if ($missing !== []) {
    $exit = 1;
    echo "FAIL: " . count($missing) . " contract endpoint(s) not registered in any plugin's register_rest_route():\n";
    foreach ($missing as $m) {
        echo sprintf(
            "  - %s %s  (docs/api-contract-v1.md:%d)\n",
            $m['method'], $m['raw'], $m['lineno']
        );
    }
    echo "\n";
} else {
    echo "PASS: every contract-declared endpoint has a matching register_rest_route() in PHP.\n\n";
}

echo "Exempt internal routes (allowlisted admin/machine — see EXEMPT_INTERNAL): " . count($exemptMatched) . "\n\n";

if ($staleExempt !== []) {
    // Blocking: a stale entry means the route was deleted or has since been
    // documented, but its exemption lingers — and a lingering exemption
    // silently pre-authorises any future route that reappears under the same
    // key. Pruning is trivial; leaving it rots the allowlist.
    $exit = 1;
    echo "FAIL: " . count($staleExempt) . " stale EXEMPT_INTERNAL allowlist entr(y/ies) — prune:\n";
    foreach ($staleExempt as $k => $why) {
        echo "  - {$k}  ({$why})\n";
    }
    echo "\n";
}

if ($undocumented !== []) {
    // Blocking as of 2026-07-24. This was a WARN while the 2026-05-26 first
    // run's backlog was being cleared (contract v1.22/v1.23 retractions); that
    // backlog reached zero, so the ratchet now engages. An undocumented
    // in-scope route is a LIVE, reachable surface that no contract review has
    // seen — precisely how forgotten endpoints accumulate. §9 requires the
    // contract be verified, not assumed, so the docs land in the same PR as
    // the route. Genuinely internal routes have an escape hatch:
    // EXEMPT_INTERNAL, with a stated reason.
    $exit = 1;
    echo "FAIL: " . count($undocumented) . " in-scope route(s) registered in PHP, undocumented in §4, and NOT allowlisted.\n";
    echo "Each is a real §γ gap: document it in docs/api-contract-v1.md §4, or — if genuinely\n";
    echo "internal — add it to the EXEMPT_INTERNAL allowlist with a reason.\n";
    foreach ($undocumented as $u) {
        echo "  - {$u}\n";
    }
    echo "\n";
} else {
    echo "PASS: every undocumented in-scope route is accounted for by the EXEMPT_INTERNAL allowlist.\n\n";
}

if ($unresolved !== []) {
    echo "\nUNRESOLVED PHP register_rest_route() sites (couldn't parse namespace or path):\n";
    foreach ($unresolved as $u) {
        echo sprintf("  - %s:%d (namespace=%s, path=%s)\n",
            str_replace($REPO_ROOT, '', $u['file']), $u['lineno'], $u['namespace'], $u['path']);
    }
    echo "\nThese sites use cross-file constants or non-string expressions; the parser couldn't resolve.\n";
    echo "Add same-file `const NAME = 'literal'` if the site is in-scope.\n";
}

exit($exit);
