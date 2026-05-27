<?php
/**
 * Contract-vs-code parity guard.
 *
 * Verifies that every endpoint declared in docs/api-contract-v1.md is
 * actually registered in PHP via register_rest_route(), and (as a
 * WARNING) flags PHP-registered endpoints that the contract does not
 * document.
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
 * Exit 0 = contract and code agree (warnings allowed).
 * Exit 1 = drift detected; missing endpoints printed with file refs.
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
    $pattern = '/^####\s+`(GET|POST|PUT|PATCH|DELETE)\s+(\/\S+?)`/';

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
|
| Class constants in the same file are resolved via a same-file
| `const NAME = '...'` lookup; cross-file constants are emitted as
| UNRESOLVED with a warning.
*/

/**
 * @return list<array{file: string, lineno: int, namespace: string, path: string, methods: list<string>, unresolved: bool}>
 */
function parse_php_routes(array $pluginRoots): array {
    $routes = [];
    foreach ($pluginRoots as $root) {
        if (!is_dir($root)) {
            continue;
        }
        $iter = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
                static function ($current, $key, $iterator) {
                    $path = (string) $current->getPathname();
                    // Skip vendored deps and tests.
                    if (strpos($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) !== false) {
                        return false;
                    }
                    if (strpos($path, DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR) !== false) {
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
            foreach (extract_routes_from_file($fpath) as $r) {
                $routes[] = $r;
            }
        }
    }
    return $routes;
}

/**
 * @return list<array{file: string, lineno: int, namespace: string, path: string, methods: list<string>, unresolved: bool}>
 */
function extract_routes_from_file(string $fpath): array {
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
                    $namespace = resolve_string_arg($args[0], $constants);
                    $path      = resolve_string_arg($args[1], $constants);
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
 *   - self::CONST  (same-file constant)
 *   - 'a' . 'b' . self::CONST . 'c'  (concatenation of the above)
 *
 * Returns null when any sub-part can't be resolved (e.g. a $variable or
 * cross-file constant). Partial resolution is intentionally NOT done —
 * a half-resolved path is worse than an honest "unresolved".
 *
 * @param list<mixed> $argTokens
 * @param array<string, string> $constants
 */
function resolve_string_arg(array $argTokens, array $constants): ?string {
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
                    $constName = $argTokens[$k][1];
                    if (isset($constants[$constName])) {
                        $parts[] = $constants[$constName];
                    } else {
                        $unresolved = true; // cross-file constant
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

$contractEndpoints = parse_contract_endpoints($CONTRACT_PATH);
$phpRoutes         = parse_php_routes($PLUGIN_ROOTS);

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
            $undocumented[] = $key;
        }
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

if ($undocumented !== []) {
    echo "WARN: " . count($undocumented) . " in-scope route(s) registered in PHP but NOT declared in docs/api-contract-v1.md §4:\n";
    foreach ($undocumented as $u) {
        echo "  - {$u}\n";
    }
    echo "\nThese are typically internal/admin routes that don't belong in the public contract,\n";
    echo "but each one should be verified — undocumented public surfaces are a §γ contract gap.\n";
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
