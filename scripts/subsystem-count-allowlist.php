<?php
/**
 * Mapped DegradationMetric subsystems that need no per-name doc claim, for
 * the map → docs direction of scripts/subsystem-count-guard.php (F-601).
 *
 * subsystem => reason. The guard fails on an entry that is no longer in
 * bcc-core.php's map, that a doc now claims, or that has no reason, so a
 * subsystem that gains its own doc line must leave this list.
 */

declare(strict_types=1);

return (static function (): array {
    $nullFamily = 'A single-`activation` NullService fallback, documented as a family rather than by name: '
        . 'pattern-registry.md "NullService activations" and GOLDEN_PATHS.md §12.1 "9 other null_* NullService activations".';

    return [
        'null_dispute_adjudication' => $nullFamily,
        'null_score_read'           => $nullFamily,
        'null_score_contributor'    => $nullFamily,
        'null_page_owner'           => $nullFamily,
        'null_wallet_link_read'     => $nullFamily,
        'null_wallet_link_write'    => $nullFamily,
        'null_wallet_signal_write'  => $nullFamily,
        'null_onchain_data_read'    => $nullFamily,
        'null_trending_data'        => $nullFamily,
    ];
})();
