<?php
/**
 * Plugin Name: BCC Query-Floor Probe (TEMPORARY diagnostic)
 * Description: Logs per-request query counts to diagnose the ~250-query boot floor. Delete after the boot-floor verification pass. Enable SAVEQUERIES in wp-config.php for per-pattern counts.
 *
 * Output goes to the PHP error log, prefixed [bcc-query-floor] — one
 * line per request:
 *   [bcc-query-floor] GET /wp-json/bcc/v1/cards total=43 show_tables=0 info_schema=0 bcc_chains=1
 *
 * Read-only: no DB writes, no options, no transients.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('shutdown', static function (): void {
    global $wpdb;

    $line = sprintf(
        '[bcc-query-floor] %s %s total=%d',
        isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'CLI',
        isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '-',
        get_num_queries()
    );

    if (defined('SAVEQUERIES') && SAVEQUERIES && !empty($wpdb->queries)) {
        $showTables = 0;
        $infoSchema = 0;
        $bccChains  = 0;
        foreach ($wpdb->queries as $q) {
            $sql = is_array($q) ? (string) $q[0] : (string) $q;
            if (stripos($sql, 'SHOW TABLES') !== false || stripos($sql, 'DESCRIBE ') !== false || stripos($sql, 'SHOW FULL COLUMNS') !== false || stripos($sql, 'SHOW INDEX') !== false) {
                $showTables++;
            }
            if (stripos($sql, 'information_schema') !== false) {
                $infoSchema++;
            }
            if (stripos($sql, 'bcc_chains') !== false) {
                $bccChains++;
            }
        }
        $line .= sprintf(' show_tables=%d info_schema=%d bcc_chains=%d', $showTables, $infoSchema, $bccChains);
    }

    error_log($line);

    // Deep dump: ?bcc_qdump=1 logs the top normalized query patterns
    // (numbers → N, IN-lists → IN(...)) so per-route N+1 sources are
    // attributable without a profiler. Local diagnostics only.
    if (isset($_GET['bcc_qdump']) && defined('SAVEQUERIES') && SAVEQUERIES && !empty($wpdb->queries)) {
        $patterns = [];
        foreach ($wpdb->queries as $q) {
            $sql = is_array($q) ? (string) $q[0] : (string) $q;
            $norm = preg_replace('/\s+/', ' ', $sql);
            $norm = preg_replace('/IN\s*\([^)]*\)/i', 'IN(...)', $norm);
            $norm = preg_replace('/\d+/', 'N', $norm);
            $norm = substr(trim((string) $norm), 0, 160);
            $patterns[$norm] = ($patterns[$norm] ?? 0) + 1;
        }
        arsort($patterns);
        $top = array_slice($patterns, 0, 15, true);
        foreach ($top as $pattern => $count) {
            error_log(sprintf('[bcc-qdump] %3dx %s', $count, $pattern));
        }
    }
}, PHP_INT_MAX);
