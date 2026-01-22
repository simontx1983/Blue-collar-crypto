<?php
/**
 * Validator Repository
 *
 * Responsible for fetching Validator domain objects.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCC_ValidatorRepository
{
    /**
     * Validator CPT slug
     */
    protected string $post_type = 'validator';

    /**
     * Get validators for a user (author-based ownership)
     *
     * @param int   $user_id
     * @param bool  $is_owner  (reserved for future visibility logic)
     * @param array $args
     * @return BCC_Validator[]
     */
    public function for_user(int $user_id, bool $is_owner, array $args = []): array
    {
        if ($user_id <= 0) {
            return [];
        }

        $query_args = [
            'post_type'      => $this->post_type,
            'post_status'    => 'publish',
            'author'         => $user_id,
            'posts_per_page' => $args['limit'] ?? 10,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ];

        $query = new WP_Query($query_args);

        if (!$query->have_posts()) {
            return [];
        }

        $validators = [];

        foreach ($query->posts as $post) {
            if (!isset($post->ID)) {
                continue;
            }

            $validator = new BCC_Validator((int) $post->ID);

            // Defensive: ensure domain object is valid
            if ($validator instanceof BCC_Validator && $validator->id() > 0) {
                $validators[] = $validator;
            }
        }

        return $validators;
    }

    /**
     * Get validators for a user filtered by network
     *
     * Filtering is done in PHP because network metrics
     * live inside an ACF repeater field.
     *
     * @param int   $user_id
     * @param bool  $is_owner
     * @param int   $network_id
     * @param array $args
     * @return BCC_Validator[]
     */
    public function for_user_by_network(
        int $user_id,
        bool $is_owner,
        int $network_id,
        array $args = []
    ): array {
        if ($user_id <= 0 || $network_id <= 0) {
            return [];
        }

        // Start with all validators for the user
        $validators = $this->for_user($user_id, $is_owner, $args);

        if (empty($validators)) {
            return [];
        }

        $filtered = [];

        foreach ($validators as $validator) {
            if (!$validator instanceof BCC_Validator) {
                continue;
            }

            // Only keep validators that support this network
            $metrics = $validator->get_network_metrics($network_id);

            if ($metrics !== null) {
                $filtered[] = $validator;
            }
        }

        // Optional sorting
        if (!empty($args['sort'])) {
            $sort = $args['sort'];

            usort($filtered, function (BCC_Validator $a, BCC_Validator $b) use ($network_id, $sort) {

                $a_metrics = $a->get_network_metrics($network_id);
                $b_metrics = $b->get_network_metrics($network_id);

                if ($sort === 'uptime_desc') {
                    return ($b_metrics['uptime'] ?? 0) <=> ($a_metrics['uptime'] ?? 0);
                }

                if ($sort === 'commission_asc') {
                    return ($a_metrics['commission'] ?? PHP_INT_MAX)
                        <=> ($b_metrics['commission'] ?? PHP_INT_MAX);
                }

                return 0;
            });
        }

        return $filtered;
    }
}
