<?php
/**
 * Domain Object: Validator
 *
 * Represents a single Validator entity.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Validator extends BCC_AbstractProfile
{
    /**
     * ------------------------------
     * Basic Fields (top-level)
     * ------------------------------
     * These are optional / descriptive.
     * NOT used for network-scoped sorting.
     */

    public function website(): ?string
    {
        return $this->field('website');
    }

    public function has_website(): bool
    {
        return !empty($this->website());
    }

    /**
     * ------------------------------
     * Network-scoped Metrics
     * ------------------------------
     * Reads per-chain metrics from repeater:
     * chains_you_validate_for
     *
     * Subfields expected:
     * - network (Relationship → Network CPT)
     * - chain_commission (Number)
     * - chain_uptime (Number)
     */
    public function get_network_metrics(int $network_id): ?array
    {
        $rows = get_field('chains_you_validate_for', $this->id());

        if (empty($rows) || !is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {

            $network = $row['network'] ?? null;
            $row_network_id = null;

            // Relationship field may return ID or post object
            if (is_object($network) && isset($network->ID)) {
                $row_network_id = (int) $network->ID;
            } elseif (is_numeric($network)) {
                $row_network_id = (int) $network;
            }

            if ($row_network_id === $network_id) {
                return [
                    'commission' => isset($row['chain_commission'])
                        ? (float) $row['chain_commission']
                        : null,

                    'uptime' => isset($row['chain_uptime'])
                        ? (float) $row['chain_uptime']
                        : null,
                ];
            }
        }

        return null;
    }

    /**
     * ------------------------------
     * Business Logic Helpers
     * ------------------------------
     * These operate on network-scoped data
     */

    public function is_low_fee_on_network(int $network_id, float $threshold = 10): bool
    {
        $metrics = $this->get_network_metrics($network_id);

        if (!$metrics || $metrics['commission'] === null) {
            return false;
        }

        return $metrics['commission'] <= $threshold;
    }

    public function has_metrics_for_network(int $network_id): bool
    {
        return $this->get_network_metrics($network_id) !== null;
    }
}
