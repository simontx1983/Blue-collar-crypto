<?php
if (!defined('ABSPATH')) exit;

/**
 * Projects – Profile Overview / View
 */

$viewed_user_id  = bcc_get_current_user_id();
$current_user_id = get_current_user_id();

if (!$viewed_user_id) {
    echo '<div class="ps-alert ps-alert--error"><p>User not found.</p></div>';
    return;
}

$is_owner = ((int) $viewed_user_id === (int) $current_user_id);

$post_status = $is_owner
    ? ['publish', 'draft', 'pending', 'private']
    : ['publish'];

$query = new WP_Query([
    'post_type'      => 'project',
    'post_status'    => $post_status,
    'author'         => $viewed_user_id,
    'posts_per_page' => 12,
    'orderby'        => 'date',
    'order'          => 'DESC',
    'no_found_rows'  => true,
]);

if (!$query->have_posts()) {
    echo '<div class="ps-alert ps-alert--info"><p>No projects found.</p></div>';
    return;
}

while ($query->have_posts()) :
    $query->the_post();

    $post_id = get_the_ID();

    // --- Optional debug (turn on temporarily) ---
    /*
    echo '<pre>';
    var_dump([
        'post_id' => $post_id,
        'status'  => get_post_status($post_id),
        'edit'    => current_user_can('edit_post', $post_id),
        'delete'  => current_user_can('delete_post', $post_id),
    ]);
    echo '</pre>';
    */

    bcc_render_peepso_card([
        'post_id' => $post_id,
        'meta'    => 'Project · ' . esc_html(get_the_date()),
        'actions' => bcc_get_post_actions($post_id, $is_owner),
    ]);

endwhile;

wp_reset_postdata();
