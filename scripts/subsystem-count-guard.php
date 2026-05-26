<?php
/**
 * Subsystem-count guard.
 *
 * Diffs the canonical DegradationMetric subsystem map in
 * bcc-core/bcc-core.php (single source of truth) against the
 * event-count claims in docs/pattern-registry.md and
 * docs/GOLDEN_PATHS.md.
 *
 * Catches the failure modes from the 2026-05-26 audit:
 *   - audit_log_swallow inline description said "3 events" while the
 *     same file (line 459) and GOLDEN_PATHS.md both said "4 events".
 *   - legacy_ajax lagging behind the 9→3 retirement until both docs
 *     were touched in lockstep.
 *
 * Exit 0 = canonical map and docs agree.
 * Exit 1 = drift detected; mismatches printed with file:line refs.
 *
 * Run from repo root:
 *   php scripts/subsystem-count-guard.php
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

$BCC_CORE        = $REPO_ROOT . '/app/public/wp-content/plugins/bcc-core/bcc-core.php';
$PATTERN_REGISTRY = $REPO_ROOT . '/docs/pattern-registry.md';
$GOLDEN_PATHS    = $REPO_ROOT . '/docs/GOLDEN_PATHS.md';

foreach ([$BCC_CORE, $PATTERN_REGISTRY, $GOLDEN_PATHS] as $required) {
    if (!is_readable($required)) {
        fwrite(STDERR, "Missing/unreadable: {$required}\n");
        exit(2);
    }
}

/*
|--------------------------------------------------------------------------
| SOURCE OF TRUTH — token-walk bcc-core.php
|--------------------------------------------------------------------------
| The canonical map lives inside DegradationMetrics::healthSnapshot([...]).
| We use PHP's tokenizer so comments and whitespace don't confuse parsing.
*/

/**
 * @return array<string, int>  subsystem name => event count
 */
function extract_canonical_map(string $path): array {
    $tokens = token_get_all((string) file_get_contents($path));
    $n      = count($tokens);
    $i      = 0;

    while ($i < $n) {
        $t = $tokens[$i];
        if (is_array($t) && $t[0] === T_STRING && $t[1] === 'healthSnapshot') {
            $j = skip_ws($tokens, $i + 1, $n);
            if ($j < $n && $tokens[$j] === '(') {
                $k = skip_ws($tokens, $j + 1, $n);
                if ($k < $n && $tokens[$k] === '[') {
                    return parse_outer_map($tokens, $k + 1, $n);
                }
            }
        }
        $i++;
    }
    throw new RuntimeException("Could not locate healthSnapshot([...]) array in {$path}");
}

function skip_ws(array $tokens, int $i, int $n): int {
    while ($i < $n && is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
        $i++;
    }
    return $i;
}

/**
 * Parse top-level entries of the canonical map. Each entry is
 *   T_CONSTANT_ENCAPSED_STRING (key) => '[' ... ']'  (event list)
 *
 * @return array<string, int>
 */
function parse_outer_map(array $tokens, int $i, int $n): array {
    $result = [];
    $depth  = 1; // we've already consumed the opening '['

    while ($i < $n && $depth > 0) {
        $t = $tokens[$i];

        if ($t === '[') { $depth++; $i++; continue; }
        if ($t === ']') { $depth--; $i++; continue; }

        if ($depth === 1 && is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
            // Possible key. Confirm next non-trivia token is '=>'.
            $j = skip_ws($tokens, $i + 1, $n);
            if ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_ARROW) {
                $key  = trim($t[1], "'\"");
                // Value should start with '[' (canonical map values are all arrays).
                $k = skip_ws($tokens, $j + 1, $n);
                if ($k < $n && $tokens[$k] === '[') {
                    [$count, $endIdx] = count_strings_in_array($tokens, $k + 1, $n);
                    $result[$key] = $count;
                    $i = $endIdx + 1;
                    continue;
                }
            }
        }

        $i++;
    }
    return $result;
}

/**
 * Count T_CONSTANT_ENCAPSED_STRING at depth 1 of an array literal whose
 * opening '[' has already been consumed. Returns [count, closingBracketIdx].
 *
 * @return array{0: int, 1: int}
 */
function count_strings_in_array(array $tokens, int $i, int $n): array {
    $count = 0;
    $depth = 1;
    while ($i < $n && $depth > 0) {
        $t = $tokens[$i];
        if ($t === '[') { $depth++; }
        elseif ($t === ']') {
            $depth--;
            if ($depth === 0) return [$count, $i];
        }
        elseif ($depth === 1 && is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
            $count++;
        }
        $i++;
    }
    throw new RuntimeException('Unbalanced array literal while counting events.');
}

/*
|--------------------------------------------------------------------------
| DOC CLAIMS — regex over markdown
|--------------------------------------------------------------------------
| Matches `subsystem_name (N events)` and `subsystem_name / N events`.
| Ignores aggregated phrases like "the 10 null_* NullService activations"
| (no slash/paren-then-digit-then-events shape).
*/

/**
 * @return list<array{lineno: int, name: string, count: int}>
 */
function extract_doc_claims(string $path): array {
    $lines  = explode("\n", (string) file_get_contents($path));
    $claims = [];
    // Word characters before a `/` or `(` followed by digits + 'event(s)'.
    // Optional backticks around the name (markdown).
    $pattern = '/`?([a-z][a-z0-9_]+)`?\s*[\/(]\s*(\d+)\s+events?\b/i';
    foreach ($lines as $idx => $line) {
        if (preg_match_all($pattern, $line, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $m) {
                $claims[] = [
                    'lineno' => $idx + 1,
                    'name'   => strtolower($m[1]),
                    'count'  => (int) $m[2],
                ];
            }
        }
    }
    return $claims;
}

/*
|--------------------------------------------------------------------------
| DIFF
|--------------------------------------------------------------------------
*/

$canonical = extract_canonical_map($BCC_CORE);

$docFiles = [
    $PATTERN_REGISTRY => 'docs/pattern-registry.md',
    $GOLDEN_PATHS    => 'docs/GOLDEN_PATHS.md',
];

$findings = [];

foreach ($docFiles as $absPath => $relPath) {
    foreach (extract_doc_claims($absPath) as $claim) {
        $name  = $claim['name'];
        $count = $claim['count'];

        if (!array_key_exists($name, $canonical)) {
            // Subsystem-shaped name claimed in docs but not in canonical
            // map. Either a typo or a ghost. Skip if it doesn't look like
            // a subsystem (heuristic: must contain an underscore).
            if (strpos($name, '_') === false) {
                continue;
            }
            $findings[] = sprintf(
                "%s:%d — claims '%s' (%d events) but no such subsystem in bcc-core.php canonical map",
                $relPath, $claim['lineno'], $name, $count
            );
            continue;
        }

        if ($canonical[$name] !== $count) {
            $findings[] = sprintf(
                "%s:%d — '%s' claims %d events but canonical map has %d",
                $relPath, $claim['lineno'], $name, $count, $canonical[$name]
            );
        }
    }
}

if ($findings === []) {
    echo "PASS: subsystem counts match between bcc-core.php and docs ("
       . count($canonical) . " subsystems checked).\n";
    exit(0);
}

echo "FAIL: subsystem-count drift detected (" . count($findings) . " finding(s)):\n";
foreach ($findings as $f) {
    echo "  - {$f}\n";
}
echo "\nFix: align the docs to the canonical map in "
   . "app/public/wp-content/plugins/bcc-core/bcc-core.php, OR if the\n"
   . "canonical map is itself wrong, fix it there (single source of truth).\n";
exit(1);
