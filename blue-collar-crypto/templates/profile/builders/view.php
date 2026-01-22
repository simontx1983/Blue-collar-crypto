<?php
if (!defined('ABSPATH')) exit;

/**
 * Builders – Profile View
 */

$viewed_user_id  = bcc_get_current_user_id();
$current_user_id = get_current_user_id();

if (!$viewed_user_id) {
    echo '<div class="ps-alert ps-alert--error"><p>User not found.</p></div>';
    return;
}

$is_owner = ((int) $viewed_user_id === (int) $current_user_id);

$query = new WP_Query([
    'post_type'      => 'builder',
    'post_status'    => $is_owner ? ['publish', 'draft', 'pending', 'private'] : ['publish'],
    'author'         => $viewed_user_id,
    'posts_per_page' => 12,
    'orderby'        => 'date',
    'order'          => 'DESC',
    'no_found_rows'  => true,
]);

if (!$query->have_posts()) {
    echo '<div class="ps-alert ps-alert--info"><p>No builders found.</p></div>';
    return;
}

while ($query->have_posts()) :
    $query->the_post();

    $post_id = get_the_ID();

    bcc_render_peepso_card([
        'post_id' => $post_id,
        'meta'    => 'Builder · ' . esc_html(get_the_date()),
        'actions' => bcc_get_post_actions($post_id, $is_owner),
    ]);

endwhile;

wp_reset_postdata();
