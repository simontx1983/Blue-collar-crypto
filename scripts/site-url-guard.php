<?php
/**
 * SITE-URL GUARD — does the DATABASE agree with this environment's identity?
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────
 * When wp-config.php defines WP_SITEURL/WP_HOME, WordPress IGNORES
 * wp_options.siteurl and wp_options.home. The site then serves correctly
 * while those rows say something else entirely — which is exactly how a
 * staging-flavoured restore sat inside production undetected. The constants
 * did not prevent the drift; they MASKED it. Only a direct read of the rows
 * can see past them, and that is all this script does.
 *
 * Production's rows were corrected on 2026-08-27 and verified by direct query
 * and against the live REST API. This is REGRESSION PROTECTION, not an
 * outstanding repair.
 *
 * ── READ-ONLY ───────────────────────────────────────────────────────────
 * Two SELECTs. No INSERT/UPDATE/DELETE/ALTER/CREATE/DROP, no migration, no
 * repair, no cache operation, no cron. Safe to run against production.
 *
 *   BCC_ENV = production | staging
 *   BCC_DB_HOST / BCC_DB_PORT / BCC_DB_USER / BCC_DB_PASS / BCC_DB_NAME
 *       — the same names scripts/schema-drift-guard.php documents.
 *
 * ── EXIT CODES ──────────────────────────────────────────────────────────
 *   0  the rows match this environment
 *   1  DRIFT — a row disagrees
 *   2  COULD NOT CHECK
 *
 * Exit 2 must FAIL wherever this is automated. A guard that silently stops
 * checking is indistinguishable from a guard that passes, and that is how a
 * guard dies. There is deliberately no "assume fine" branch anywhere below.
 *
 * @see docs/hosting.md
 */

/**
 * Expected identity per environment.
 *
 * PRODUCTION IS ASYMMETRIC ON PURPOSE: WordPress serves from `cms.`, while the
 * public site is the Vercel frontend on the apex. Do NOT "fix" home to cms. —
 * that would point visitors at WordPress and bypass the frontend.
 */
$EXPECTED = [
    'production' => [
        'siteurl' => 'https://cms.bluecollarcrypto.io',
        'home'    => 'https://bluecollarcrypto.io',
    ],
    'staging' => [
        'siteurl' => 'https://stage.bluecollarcrypto.io',
        'home'    => 'https://stage.bluecollarcrypto.io',
    ],
];

/**
 * Stop with exit 2. Never interpolates a credential: mysqli's own error
 * strings can echo the user and database name, so they are not printed.
 */
function cannot_check(string $why): void
{
    fwrite(STDERR, "site-url-guard: CANNOT CHECK — {$why}\n");
    exit(2);
}

$env = getenv('BCC_ENV') ?: '';
if (!isset($EXPECTED[$env])) {
    cannot_check('set BCC_ENV to one of: ' . implode(', ', array_keys($EXPECTED)));
}
if (!function_exists('mysqli_connect')) {
    cannot_check('mysqli extension not available in this PHP CLI');
}

$host = getenv('BCC_DB_HOST') ?: '';
$port = (int) (getenv('BCC_DB_PORT') ?: 3306);
$user = getenv('BCC_DB_USER') ?: '';
$pass = (string) getenv('BCC_DB_PASS');
$name = getenv('BCC_DB_NAME') ?: '';

if ($host === '' || $user === '' || $name === '') {
    cannot_check('no connection params resolvable (need BCC_DB_HOST/USER/NAME)');
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @mysqli_connect($host, $user, $pass, $name, $port);
if (!($conn instanceof mysqli)) {
    cannot_check('connection failed');
}

/* ── Prefix discovery: never guess which options table to read ─────────────
 *
 * Collect EVERY candidate and require exactly one. A multisite install
 * (wp_options + wp_2_options), two WordPress installs sharing one database, or
 * a plugin table that happens to end in "options" all yield several — and
 * picking one by any heuristic (shortest, first, alphabetical) could read a
 * DIFFERENT site's identity and report a confident, wrong answer. That is
 * worse than not checking.
 *
 * Ambiguity is therefore a CANNOT CHECK, not a coin flip.
 *
 * Note: production has exactly one (`wp_options`). Staging's count is
 * unverified at the time of writing — if this exits 2 there naming several
 * tables, that is the guard working, not failing.
 */
$candidates = [];
$res = @mysqli_query(
    $conn,
    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%options'"
);
if (!($res instanceof mysqli_result)) {
    cannot_check('could not list tables');
}
while ($row = mysqli_fetch_row($res)) {
    $candidates[] = substr((string) $row[0], 0, -strlen('options'));
}
mysqli_free_result($res);

if (count($candidates) === 0) {
    cannot_check('no *options table in this database');
}
if (count($candidates) > 1) {
    $named = implode(', ', array_map(static fn(string $p): string => $p . 'options', $candidates));
    cannot_check("ambiguous options tables ({$named}) — refusing to guess which site to read");
}

$prefix = $candidates[0];

/* The prefix is interpolated into a backticked identifier, which cannot be
 * parameterised. It comes from INFORMATION_SCHEMA rather than user input, but
 * validate it anyway: a backtick or space in a table name would break out of
 * the quoting, and "it can't happen" is not a control. */
if ($prefix !== '' && preg_match('/^[A-Za-z0-9_]+$/', $prefix) !== 1) {
    cannot_check('options table prefix has unexpected characters — refusing to interpolate it');
}

$table = $prefix . 'options';
$stmt  = mysqli_prepare(
    $conn,
    "SELECT option_name, option_value FROM `{$table}`
      WHERE option_name IN ('siteurl','home')"
);
if ($stmt === false || !mysqli_stmt_execute($stmt)) {
    cannot_check('read failed');
}
$res = mysqli_stmt_get_result($stmt);
if (!($res instanceof mysqli_result)) {
    cannot_check('read returned no result set');
}

$actual = [];
while ($row = mysqli_fetch_assoc($res)) {
    // Trailing slashes are cosmetic in these options; compare without one.
    $actual[(string) $row['option_name']] = rtrim((string) $row['option_value'], '/');
}
mysqli_free_result($res);

printf("site-url-guard  env=%s  options_table=%s\n", $env, $table);

$drift = 0;
foreach ($EXPECTED[$env] as $option => $want) {
    // A MISSING row is drift, not a pass. WordPress would fall back to the
    // constants and keep serving, which is the state this guard exists to see.
    $got = $actual[$option] ?? '(row missing)';
    $ok  = ($got === $want);
    printf("  %-8s expected=%-38s actual=%-38s %s\n", $option, $want, $got, $ok ? 'OK' : 'DRIFT');
    if (!$ok) {
        $drift++;
    }
}

// Name the specific failure that started all this, so the message is actionable.
foreach ($actual as $option => $got) {
    if ($env !== 'staging' && str_contains($got, 'stage.bluecollarcrypto.io')) {
        printf("  !! %s carries a STAGING host in a %s database\n", $option, $env);
    }
}

echo $drift === 0
    ? "PASS: database identity matches {$env}.\n"
    : "FAIL: {$drift} option(s) drifted. Correct the DB row — do NOT edit this guard.\n";

exit($drift === 0 ? 0 : 1);
