<?php
/**
 * Dead-PHP-File Scan — bcc-core / bcc-search / bcc-trust
 *
 * Static reference scan: enumerate every PHP file under the three
 * BCC plugins' source roots, extract declared classes/interfaces/
 * traits/enums via PHP's tokenizer, and grep the entire plugin tree
 * for each symbol's FQCN and short name.
 *
 * Files whose declared symbols have no outside references — or
 * procedural files that no other file `require`s — are flagged as
 * dead candidates in one of four confidence tiers.
 *
 * Run from repo root:
 *   php scripts/dead-file-scan.php > dead-file-report.md
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from CLI.\n");
    exit(1);
}

/*
|--------------------------------------------------------------------------
| CONFIGURATION
|--------------------------------------------------------------------------
*/

$REPO_ROOT = realpath(__DIR__ . '/..');
if ($REPO_ROOT === false) {
    fwrite(STDERR, "Cannot resolve repo root.\n");
    exit(1);
}

// Source roots whose files are candidates for dead-detection.
$PLUGIN_SOURCE_ROOTS = [
    'app/public/wp-content/plugins/bcc-core/src',
    'app/public/wp-content/plugins/bcc-search/app',
    'app/public/wp-content/plugins/bcc-trust/app',
    'app/public/wp-content/plugins/bcc-trust/includes',
];

// All plugin trees searched for *references* (includes entry files,
// uninstall, tests — anything that might reference a class). Wider
// than the candidate set on purpose.
$PLUGIN_REFERENCE_ROOTS = [
    'app/public/wp-content/plugins/bcc-core',
    'app/public/wp-content/plugins/bcc-search',
    'app/public/wp-content/plugins/bcc-trust',
];

// Directories never scanned (neither as candidates nor as references).
$EXCLUDE_DIR_NAMES = [
    'vendor',
    'node_modules',
    '.next',
    '.git',
];

// Files never flagged as dead even if no other file references them.
// WordPress loads these directly; uninstall.php is invoked by WP on
// plugin uninstall.
$ENTRY_FILE_BASENAMES = [
    'bcc-core.php',
    'bcc-search.php',
    'bcc-trust.php',
    'uninstall.php',
];

// Test directories are scanned for references (so a test referencing
// a class still counts) but their own files are not candidates.
$TEST_DIR_SEGMENT = '/tests/';

$INCLUDE_TESTS_AS_REFERENCES = true; // Tier B promotion: if false, test refs don't count.

/*
|--------------------------------------------------------------------------
| FILE ENUMERATION
|--------------------------------------------------------------------------
*/

function enumerate_php(string $base, array $excludeDirNames): array
{
    if (!is_dir($base)) {
        return [];
    }
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS),
            function ($current) use ($excludeDirNames) {
                if ($current->isDir() && in_array($current->getFilename(), $excludeDirNames, true)) {
                    return false;
                }
                return true;
            }
        )
    );
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
            $out[] = str_replace('\\', '/', $f->getPathname());
        }
    }
    sort($out);
    return $out;
}

$candidateFiles = [];
foreach ($PLUGIN_SOURCE_ROOTS as $rel) {
    $abs = $REPO_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    foreach (enumerate_php($abs, $EXCLUDE_DIR_NAMES) as $f) {
        $candidateFiles[$f] = true;
    }
}

$referenceFiles = [];
foreach ($PLUGIN_REFERENCE_ROOTS as $rel) {
    $abs = $REPO_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    foreach (enumerate_php($abs, $EXCLUDE_DIR_NAMES) as $f) {
        $referenceFiles[$f] = true;
    }
}

// Drop test files + entry files from candidate set.
foreach (array_keys($candidateFiles) as $f) {
    $base = basename($f);
    if (in_array($base, $ENTRY_FILE_BASENAMES, true)) {
        unset($candidateFiles[$f]);
        continue;
    }
    if (strpos($f, $TEST_DIR_SEGMENT) !== false) {
        unset($candidateFiles[$f]);
        continue;
    }
}

$candidateFiles = array_keys($candidateFiles);
$referenceFiles = array_keys($referenceFiles);

/*
|--------------------------------------------------------------------------
| TOKENIZE — extract namespace + declared symbols per candidate file
|--------------------------------------------------------------------------
*/

/**
 * @return array{namespace: string, symbols: array<int, array{kind: string, name: string}>}
 */
function tokenize_file(string $path): array
{
    $src = @file_get_contents($path);
    if ($src === false) {
        return ['namespace' => '', 'symbols' => []];
    }
    $tokens = @token_get_all($src);
    if (!is_array($tokens)) {
        return ['namespace' => '', 'symbols' => []];
    }

    $namespace = '';
    $symbols   = [];
    $n         = count($tokens);
    $T_ENUM    = defined('T_ENUM') ? T_ENUM : -1;

    for ($i = 0; $i < $n; $i++) {
        $tok = $tokens[$i];
        if (!is_array($tok)) {
            continue;
        }
        [$id, $text] = [$tok[0], $tok[1]];

        if ($id === T_NAMESPACE) {
            // Collect namespace name until ; or {
            $ns = '';
            for ($j = $i + 1; $j < $n; $j++) {
                $t = $tokens[$j];
                if (is_string($t)) {
                    if ($t === ';' || $t === '{') break;
                    continue;
                }
                // T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED
                if (in_array($t[0], [T_STRING, T_NS_SEPARATOR], true)
                    || (defined('T_NAME_QUALIFIED') && $t[0] === T_NAME_QUALIFIED)
                    || (defined('T_NAME_FULLY_QUALIFIED') && $t[0] === T_NAME_FULLY_QUALIFIED)
                ) {
                    $ns .= $t[1];
                }
            }
            $namespace = trim($ns, '\\');
            continue;
        }

        $isDecl = ($id === T_CLASS || $id === T_INTERFACE || $id === T_TRAIT || $id === $T_ENUM);
        if (!$isDecl) {
            continue;
        }

        // Skip ::class (T_CLASS preceded by T_DOUBLE_COLON).
        if ($id === T_CLASS) {
            for ($k = $i - 1; $k >= 0; $k--) {
                $prev = $tokens[$k];
                if (is_array($prev) && in_array($prev[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if (is_array($prev) && $prev[0] === T_DOUBLE_COLON) {
                    continue 2; // ::class — not a declaration
                }
                break;
            }
        }

        // Find the next T_STRING — that's the declared name. If we
        // see '(' first, it's an anonymous class; skip.
        for ($j = $i + 1; $j < $n; $j++) {
            $t = $tokens[$j];
            if (is_array($t)) {
                if ($t[0] === T_WHITESPACE || $t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                if ($t[0] === T_STRING) {
                    $kind = match ($id) {
                        T_CLASS     => 'class',
                        T_INTERFACE => 'interface',
                        T_TRAIT     => 'trait',
                        default     => 'enum',
                    };
                    $symbols[] = ['kind' => $kind, 'name' => $t[1]];
                    break;
                }
                // Modifiers like `extends`, `implements` shouldn't precede the name. Bail.
                break;
            } else {
                // string token like '(' → anonymous class
                break;
            }
        }
    }

    return ['namespace' => $namespace, 'symbols' => $symbols];
}

$fileInfo = []; // path => ['namespace' => ..., 'symbols' => [...]]
foreach ($candidateFiles as $f) {
    $fileInfo[$f] = tokenize_file($f);
}

/*
|--------------------------------------------------------------------------
| LOAD ALL REFERENCE FILE CONTENTS INTO MEMORY (single pass)
|--------------------------------------------------------------------------
*/

$referenceContents = []; // path => string contents
foreach ($referenceFiles as $f) {
    $c = @file_get_contents($f);
    if ($c !== false) {
        $referenceContents[$f] = $c;
    }
}

/*
|--------------------------------------------------------------------------
| REFERENCE SEARCH
|--------------------------------------------------------------------------
*/

/**
 * Returns list of reference-file paths (other than $excludeSelf) that
 * mention $fqcn (FQCN form) OR contain the short name as a word.
 *
 * @param string $fqcn        Full FQCN, no leading backslash
 * @param string $shortName   Class short name
 * @param string $excludeSelf Declaring file path (skipped)
 * @return list<string>
 */
function find_references(string $fqcn, string $shortName, string $excludeSelf, array &$contents): array
{
    $hits = [];
    // Pattern A: FQCN literal (e.g., `BCC\Trust\Core\Foo`).
    // Pattern B: FQCN with double-escaped backslashes in a string literal
    //            (e.g., `'BCC\\Trust\\Core\\Foo'`).
    // Pattern C: short name as a word — \bShortName\b.
    $shortPattern = '/\\b' . preg_quote($shortName, '/') . '\\b/';
    $fqcnDoubled = str_replace('\\', '\\\\', $fqcn);

    foreach ($contents as $path => $src) {
        if ($path === $excludeSelf) {
            continue;
        }
        if ($fqcn !== '' && strpos($src, $fqcn) !== false) {
            $hits[] = $path;
            continue;
        }
        if ($fqcn !== '' && $fqcnDoubled !== $fqcn && strpos($src, $fqcnDoubled) !== false) {
            $hits[] = $path;
            continue;
        }
        if (preg_match($shortPattern, $src) === 1) {
            $hits[] = $path;
            continue;
        }
    }
    return $hits;
}

/**
 * For a procedural file (no declared class), check whether its
 * basename appears in any reference file (heuristic for require).
 *
 * @return list<string>
 */
function find_require_references(string $candidatePath, string $excludeSelf, array &$contents): array
{
    $base = basename($candidatePath);
    $hits = [];
    foreach ($contents as $path => $src) {
        if ($path === $excludeSelf) {
            continue;
        }
        if (strpos($src, $base) !== false) {
            $hits[] = $path;
        }
    }
    return $hits;
}

/*
|--------------------------------------------------------------------------
| CLASSIFY
|--------------------------------------------------------------------------
*/

$tierA = []; // class declared, zero refs anywhere
$tierB = []; // class declared, refs only in tests/
$tierC = []; // procedural file, basename appears nowhere else
$ambiguous = []; // procedural file with possible refs (verify manually)

foreach ($candidateFiles as $f) {
    $info = $fileInfo[$f];
    $ns   = $info['namespace'];

    if (count($info['symbols']) === 0) {
        // Procedural file → C
        $refs = find_require_references($f, $f, $referenceContents);
        if (count($refs) === 0) {
            $tierC[] = ['file' => $f, 'reason' => 'basename absent from all other PHP files'];
        }
        continue;
    }

    // Class-bearing file: collect refs for each symbol; take the union.
    $allRefs = [];
    $symbolDetail = [];
    foreach ($info['symbols'] as $sym) {
        $fqcn = $ns !== '' ? $ns . '\\' . $sym['name'] : $sym['name'];
        $refs = find_references($fqcn, $sym['name'], $f, $referenceContents);
        $symbolDetail[] = [
            'kind' => $sym['kind'],
            'fqcn' => $fqcn,
            'refs' => $refs,
        ];
        foreach ($refs as $r) {
            $allRefs[$r] = true;
        }
    }
    $allRefs = array_keys($allRefs);

    if (count($allRefs) === 0) {
        $tierA[] = ['file' => $f, 'symbols' => $symbolDetail];
        continue;
    }

    // Are all references inside /tests/?
    $nonTest = array_filter($allRefs, fn($r) => strpos($r, '/tests/') === false);
    if (count($nonTest) === 0) {
        $tierB[] = ['file' => $f, 'symbols' => $symbolDetail];
    }
}

/*
|--------------------------------------------------------------------------
| REPORT
|--------------------------------------------------------------------------
*/

function rel(string $abs, string $repoRoot): string
{
    $abs = str_replace('\\', '/', $abs);
    $repoRoot = str_replace('\\', '/', $repoRoot);
    if (strpos($abs, $repoRoot) === 0) {
        return ltrim(substr($abs, strlen($repoRoot)), '/');
    }
    return $abs;
}

$totalCandidates = count($candidateFiles);
$totalRefFiles   = count($referenceFiles);

echo "# Dead-PHP-File Scan Report\n\n";
echo '_Generated: ' . date('Y-m-d H:i:s') . "_\n\n";
echo "Scanned **{$totalCandidates}** candidate files across the three BCC plugin source roots. ";
echo "Reference index covers **{$totalRefFiles}** PHP files (full plugin trees, including entry files and tests).\n\n";

echo "## Summary\n\n";
echo '| Tier | Description | Count |' . "\n";
echo '|------|-------------|-------|' . "\n";
echo '| A | Class declared, zero references | ' . count($tierA) . " |\n";
echo '| B | Class declared, referenced only from tests/ | ' . count($tierB) . " |\n";
echo '| C | Procedural file, basename appears nowhere else | ' . count($tierC) . " |\n\n";

echo "## Tier A — High confidence dead\n\n";
if (count($tierA) === 0) {
    echo "_None._\n\n";
} else {
    foreach ($tierA as $row) {
        $relPath = rel($row['file'], $REPO_ROOT);
        echo "### {$relPath}\n\n";
        foreach ($row['symbols'] as $s) {
            echo "- `{$s['kind']} {$s['fqcn']}` — 0 references\n";
        }
        echo "\n";
    }
}

echo "## Tier B — Referenced only from tests/\n\n";
if (count($tierB) === 0) {
    echo "_None._\n\n";
} else {
    foreach ($tierB as $row) {
        $relPath = rel($row['file'], $REPO_ROOT);
        echo "### {$relPath}\n\n";
        foreach ($row['symbols'] as $s) {
            $refRel = array_map(fn($r) => rel($r, $REPO_ROOT), $s['refs']);
            echo "- `{$s['kind']} {$s['fqcn']}` — referenced from: " . implode(', ', $refRel) . "\n";
        }
        echo "\n";
    }
}

echo "## Tier C — Procedural files with no apparent require\n\n";
if (count($tierC) === 0) {
    echo "_None._\n\n";
} else {
    foreach ($tierC as $row) {
        $relPath = rel($row['file'], $REPO_ROOT);
        echo "- `{$relPath}` — {$row['reason']}\n";
    }
    echo "\n";
}

echo "## Notes on confidence\n\n";
echo "- The scanner matches both FQCN and short-name references. Short-name matches use word-boundary regex, so `Logger` won't match `MyLogger`.\n";
echo "- Quoted-string class names (`'BCC\\\\Trust\\\\…'`) are matched in both forms.\n";
echo "- Tier A is the highest-confidence candidate for deletion but still warrants a manual look — a class may be wired up by a mechanism the scanner doesn't see (e.g., reflection, plugin-external code).\n";
echo "- Tier C uses basename-presence as a heuristic for `require`/`include`. A unique basename appearing nowhere else is a strong signal; a generic name (`config.php`) is less reliable.\n";
echo "- Test files are excluded from the candidate set. References *from* tests count by default (use `INCLUDE_TESTS_AS_REFERENCES = false` in the script to flip this — then test-only classes promote into Tier A).\n";
