<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get action links for a post (profile cards)
 *
 * Rules:
 * - View: ONLY when published
 * - Edit: ALWAYS for owner
 * - Unpublish: ONLY when published
 * - Delete: ALWAYS for owner
 *
 * @param int  $post_id
 * @param bool $is_owner
 * @return array
 */
function bcc_get_post_actions(int $post_id, bool $is_owner): array
{
    // Safety guard
    if (!$post_id || !$is_owner) {
        return [];
    }

    $actions = [];
    $status  = get_post_status($post_id);

    // -----------------------------
    // VIEW (published only)
    // -----------------------------
    if ($status === 'publish') {
        $actions[] = [
            'label' => 'View',
            'url'   => get_permalink($post_id),
        ];
    }

    // -----------------------------
    // EDIT (always for owner)
    // -----------------------------
    $actions[] = [
        'label'  => 'Edit',
        'url'    => get_edit_post_link($post_id),
        'target' => '_blank',
    ];

    // -----------------------------
    // UNPUBLISH (published only)
    // -----------------------------
    if ($status === 'publish') {
        $actions[] = [
            'label' => 'Unpublish',
            'url'   => wp_nonce_url(
                admin_url('admin-post.php?action=bcc_unpublish_post&post_id=' . $post_id),
                'bcc_unpublish_' . $post_id
            ),
        ];
    }

    // -----------------------------
    // DELETE (always for owner)
    // -----------------------------
    $actions[] = [
        'label' => 'Delete',
        'url'   => wp_nonce_url(
            admin_url('admin-post.php?action=bcc_delete_post&post_id=' . $post_id),
            'bcc_delete_' . $post_id
        ),
    ];

    return $actions;
}
