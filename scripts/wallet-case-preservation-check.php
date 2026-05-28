<?php
/**
 * Wallet Address Case-Preservation Check
 *
 * One-shot verification that WalletRepository preserves wallet_address
 * case on insert (so SS58 base58check doesn't get corrupted) AND that
 * the column collation utf8mb4_unicode_520_ci continues to dedup
 * case-insensitively across the UNIQUE keys.
 *
 * Run from repo root against a Local-by-Flywheel site:
 *   php scripts/wallet-case-preservation-check.php
 *
 * Throwaway — kept under scripts/ for future re-run, not wired to CI.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from CLI.\n");
    exit(1);
}

// Local-by-Flywheel: MySQL runs on a per-site port (e.g. 10014). wp-config
// defines DB_HOST as 'localhost' which only works inside Local's PHP-FPM
// environment. Allow override via env var, otherwise read sites.json.
if (!defined('DB_HOST')) {
    $dbHost = getenv('BCC_DB_HOST') ?: '';
    if ($dbHost === '') {
        $sitesJson = (string) getenv('APPDATA') . '/Local/sites.json';
        if (is_file($sitesJson)) {
            $sites = json_decode((string) file_get_contents($sitesJson), true);
            if (is_array($sites)) {
                foreach ($sites as $site) {
                    if (stripos((string) ($site['name'] ?? ''), 'blue') !== false) {
                        $port = $site['services']['mysql']['ports']['MYSQL'][0] ?? null;
                        if ($port !== null) {
                            $dbHost = '127.0.0.1:' . (int) $port;
                        }
                        break;
                    }
                }
            }
        }
    }
    if ($dbHost !== '') {
        define('DB_HOST', $dbHost);
    }
}

$wpLoad = __DIR__ . '/../app/public/wp-load.php';
if (!is_file($wpLoad)) {
    fwrite(STDERR, "wp-load.php not found at {$wpLoad}\n");
    exit(1);
}
require $wpLoad;

// CLI bootstrap doesn't always fire the plugins_loaded chain that
// registers each plugin's composer autoloader. Force-load both
// plugin entry files so their PSR-4 autoloaders register before we
// touch repository classes.
foreach ([
    __DIR__ . '/../app/public/wp-content/plugins/bcc-core/bcc-core.php',
    __DIR__ . '/../app/public/wp-content/plugins/bcc-trust/bcc-trust.php',
] as $pluginFile) {
    if (is_file($pluginFile)) {
        require_once $pluginFile;
    }
}

if (!class_exists('\\BCC\\Trust\\Onchain\\Repositories\\WalletRepository')) {
    fwrite(STDERR, "WalletRepository not loaded — is bcc-trust active?\n");
    exit(1);
}
if (!class_exists('\\BCC\\Trust\\Onchain\\Repositories\\ChainRepository')) {
    fwrite(STDERR, "ChainRepository not loaded — is bcc-trust active?\n");
    exit(1);
}

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\WalletRepository;

/** @var \wpdb $wpdb */
global $wpdb;

$polkadotChain = ChainRepository::getBySlug('polkadot');
if (!$polkadotChain) {
    fwrite(STDERR, "No 'polkadot' chain row in bcc_chains — cannot run check.\n");
    exit(1);
}
$chainId = (int) $polkadotChain->id;

// Canonical Polkadot test vector (Alice). Mixed case is the wallet-rendered form.
$mixedCase = '5GrwvaEF5zXb26Fz9rcQpDWS57CtERHpNehXCPcNoHGKutQY';
$lowerCase = strtolower($mixedCase);
$userId    = 1;

$table = WalletRepository::table();

$failures = [];
$report   = [];

// Sweep any prior test row before we start (collation-insensitive match).
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$table} WHERE chain_id = %d AND wallet_address = %s",
    $chainId,
    $mixedCase
));

// --- 1. Insert mixed-case → row stored character-for-character ----------
$insert1 = WalletRepository::insertOrFind([
    'user_id'        => $userId,
    'post_id'        => 0,
    'wallet_address' => $mixedCase,
    'chain_id'       => $chainId,
    'wallet_type'    => 'user',
    'label'          => 'case-check',
]);
$report[] = sprintf("insert mixed-case: id=%d inserted=%s", $insert1['id'], $insert1['inserted'] ? 'true' : 'false');
if ($insert1['id'] <= 0 || !$insert1['inserted']) {
    $failures[] = "[1] expected fresh insert of mixed-case row, got id={$insert1['id']} inserted=" . ($insert1['inserted'] ? 'true' : 'false');
}

$stored = (string) $wpdb->get_var($wpdb->prepare(
    "SELECT wallet_address FROM {$table} WHERE id = %d",
    $insert1['id']
));
$report[] = sprintf("stored wallet_address: %s", $stored);
if ($stored !== $mixedCase) {
    $failures[] = "[2] case corruption — expected '{$mixedCase}' got '{$stored}'";
}

// --- 2. Re-insert lowercase variant → collation dedups, no new row ------
$insert2 = WalletRepository::insertOrFind([
    'user_id'        => $userId,
    'post_id'        => 0,
    'wallet_address' => $lowerCase,
    'chain_id'       => $chainId,
    'wallet_type'    => 'user',
    'label'          => 'case-check-dup',
]);
$report[] = sprintf("insert lowercase variant: id=%d inserted=%s", $insert2['id'], $insert2['inserted'] ? 'true' : 'false');
if ($insert2['inserted'] !== false || $insert2['id'] !== $insert1['id']) {
    $failures[] = "[3] expected collation to dedup the lowercase variant against the mixed-case row, got id={$insert2['id']} inserted=" . ($insert2['inserted'] ? 'true' : 'false');
}

// Confirm the stored case is still the original.
$storedAfter = (string) $wpdb->get_var($wpdb->prepare(
    "SELECT wallet_address FROM {$table} WHERE id = %d",
    $insert1['id']
));
if ($storedAfter !== $mixedCase) {
    $failures[] = "[4] re-insert mutated the stored case from '{$mixedCase}' to '{$storedAfter}'";
}

// --- 3. exists / existsForOtherUser indifferent to query case -----------
$existsMixed = WalletRepository::exists($userId, $chainId, $mixedCase);
$existsLower = WalletRepository::exists($userId, $chainId, $lowerCase);
$report[]    = sprintf("exists(mixed)=%s exists(lower)=%s", $existsMixed ? 'true' : 'false', $existsLower ? 'true' : 'false');
if (!$existsMixed || !$existsLower) {
    $failures[] = "[5] exists() should be true for both case variants — got mixed=" . ($existsMixed ? 'true' : 'false') . " lower=" . ($existsLower ? 'true' : 'false');
}

$otherMixed = WalletRepository::existsForOtherUser($userId + 999999, $chainId, $mixedCase);
$otherLower = WalletRepository::existsForOtherUser($userId + 999999, $chainId, $lowerCase);
$report[]   = sprintf("existsForOtherUser(mixed)=%s existsForOtherUser(lower)=%s", $otherMixed ? 'true' : 'false', $otherLower ? 'true' : 'false');
if (!$otherMixed || !$otherLower) {
    $failures[] = "[6] existsForOtherUser() should be true for both case variants — got mixed=" . ($otherMixed ? 'true' : 'false') . " lower=" . ($otherLower ? 'true' : 'false');
}

// --- 4. findUserIdByAddress / findIdByUserChainAddress symmetry ---------
$foundUserMixed = WalletRepository::findUserIdByAddress($chainId, $mixedCase);
$foundUserLower = WalletRepository::findUserIdByAddress($chainId, $lowerCase);
$report[]       = sprintf("findUserIdByAddress(mixed)=%d findUserIdByAddress(lower)=%d", $foundUserMixed, $foundUserLower);
if ($foundUserMixed !== $userId || $foundUserLower !== $userId) {
    $failures[] = "[7] findUserIdByAddress should resolve user {$userId} for both case variants — got mixed={$foundUserMixed} lower={$foundUserLower}";
}

$foundIdMixed = WalletRepository::findIdByUserChainAddress($userId, $chainId, $mixedCase);
$foundIdLower = WalletRepository::findIdByUserChainAddress($userId, $chainId, $lowerCase);
$report[]     = sprintf("findIdByUserChainAddress(mixed)=%d findIdByUserChainAddress(lower)=%d", $foundIdMixed, $foundIdLower);
if ($foundIdMixed !== $insert1['id'] || $foundIdLower !== $insert1['id']) {
    $failures[] = "[8] findIdByUserChainAddress should resolve link id {$insert1['id']} for both case variants — got mixed={$foundIdMixed} lower={$foundIdLower}";
}

// --- Cleanup ------------------------------------------------------------
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$table} WHERE id = %d",
    $insert1['id']
));

// --- Report -------------------------------------------------------------
fwrite(STDOUT, "=== wallet-case-preservation-check ===\n");
foreach ($report as $line) {
    fwrite(STDOUT, "  {$line}\n");
}
fwrite(STDOUT, "\n");

if ($failures === []) {
    fwrite(STDOUT, "PASS — case preserved, collation dedups, lookups symmetric.\n");
    exit(0);
}

fwrite(STDERR, "FAIL\n");
foreach ($failures as $f) {
    fwrite(STDERR, "  - {$f}\n");
}
exit(1);
