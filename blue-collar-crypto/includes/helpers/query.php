<?php
/**
 * Query Helpers
 *
 * Centralized helper functions for fetching data.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get projects by user ID
 *
 * @param int   $user_id
 * @param array $args
 * @return WP_Query|false
 */
function bcc_get_projects_by_user(int $user_id, array $args = [])
{
    if (!$user_id) {
        return false;
    }

    $defaults = [
        'post_type'      => 'project',
        'post_status'    => 'publish',
        'posts_per_page' => 6,
        'author'         => $user_id,
        'no_found_rows'  => true,
    ];

    return new WP_Query(wp_parse_args($args, $defaults));
}

/**
 * Get current PeepSo user ID (viewed profile or current user)
 *
 * @return int
 */
function bcc_get_current_user_id(): int
{
    $user_id = 0;

    if (class_exists('PeepSoProfileShortcode')) {
        $peepso = PeepSoProfileShortcode::get_instance();
        if ($peepso && method_exists($peepso, 'get_view_user_id')) {
            $user_id = (int) $peepso->get_view_user_id();
        }
    }

    if (!$user_id) {
        $user_id = (int) get_current_user_id();
    }

    return $user_id;
}

/**
 * Get Network filter options
 *
 * IMPORTANT:
 * - Uses Network CPT (NOT taxonomy)
 * - Returns Network POST IDs
 * - Must match ACF Relationship field in Validator repeater
 *
 * @return array [network_id => network_title]
 */
function bcc_get_network_filter_options(): array
{
    $networks = get_posts([
        'post_type'      => 'network',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC',
        'no_found_rows'  => true,
    ]);

    $options = [];

    if (!empty($networks)) {
        foreach ($networks as $network) {
            $options[(int) $network->ID] = $network->post_title;
        }
    }

    return $options;
}
