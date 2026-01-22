<?php
if (!defined('ABSPATH')) exit;

/**
 * Handle unpublish post
 */
add_action('admin_post_bcc_unpublish_post', function () {

    if (empty($_GET['post_id']) || empty($_GET['_wpnonce'])) {
        wp_die('Invalid request');
    }

    $post_id = (int) $_GET['post_id'];

    if (!wp_verify_nonce($_GET['_wpnonce'], 'bcc_unpublish_' . $post_id)) {
        wp_die('Security check failed');
    }

    if (!current_user_can('edit_post', $post_id)) {
        wp_die('Permission denied');
    }

    wp_update_post([
        'ID'          => $post_id,
        'post_status' => 'draft',
    ]);

    wp_safe_redirect(wp_get_referer() ?: home_url());
    exit;
});

/**
 * Handle delete post
 */
add_action('admin_post_bcc_delete_post', function () {

    if (empty($_GET['post_id']) || empty($_GET['_wpnonce'])) {
        wp_die('Invalid request');
    }

    $post_id = (int) $_GET['post_id'];

    if (!wp_verify_nonce($_GET['_wpnonce'], 'bcc_delete_' . $post_id)) {
        wp_die('Security check failed');
    }

    if (!current_user_can('delete_post', $post_id)) {
        wp_die('Permission denied');
    }

    wp_delete_post($post_id, true);

    wp_safe_redirect(wp_get_referer() ?: home_url());
    exit;
});
