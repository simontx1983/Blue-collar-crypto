<?php
/**
 * Render Helpers
 *
 * Shared UI renderers for Blue Collar Crypto.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get status badge HTML for a post
 *
 * Rendered as a corner ribbon / flag.
 */
function bcc_get_status_badge(int $post_id): string
{
    $status = get_post_status($post_id);

    $map = [
        'publish' => ['label' => 'Live',    'class' => 'bcc-badge--published'],
        'draft'   => ['label' => 'Draft',   'class' => 'bcc-badge--draft'],
        'private' => ['label' => 'Private', 'class' => 'bcc-badge--private'],
        'pending' => ['label' => 'Pending', 'class' => 'bcc-badge--pending'],
    ];

    if (!isset($map[$status])) {
        return '';
    }

    return sprintf(
        '<span class="bcc-status-badge %s">%s</span>',
        esc_attr($map[$status]['class']),
        esc_html($map[$status]['label'])
    );
}

/**
 * Render a PeepSo-style blog card
 *
 * @param array $args {
 *   @type int    $post_id   Required. Post ID to render.
 *   @type string $meta      Optional. Meta text (HTML allowed).
 *   @type string $content   Optional. Custom HTML content.
 *   @type array  $actions   Optional. Array of action links.
 * }
 */
function bcc_render_peepso_card(array $args = []): void
{
    // --------------------------------------------------
    // Validate input
    // --------------------------------------------------
    if (empty($args['post_id'])) {
        return;
    }

    $post = get_post((int) $args['post_id']);
    if (!$post) {
        return;
    }

    setup_postdata($post);

    $meta    = $args['meta']    ?? '';
    $content = $args['content'] ?? '';
    $actions = $args['actions'] ?? [];

    // --------------------------------------------------
    // PeepSo featured image configuration
    // --------------------------------------------------
    $image_position = '';
    $image_size     = 'medium';

    if (class_exists('PeepSo')) {
        $position = PeepSo::get_option('blogposts_profile_featured_image_position');

        if ($position === 'left') {
            $image_position = 'ps-blogposts__post-image--left';
        }

        if ($position === 'right') {
            $image_position = 'ps-blogposts__post-image--right';
        }

        if ($position === 'top') {
            $image_position = 'ps-blogposts__post-image--top';
            $image_size     = 'large';
        }
    }
    ?>

    <div class="ps-blogposts__post bcc-card">
        <div class="ps-blogposts__post-inside">
            <div class="ps-blogposts__post-body">

                <!-- Status flag (owner only) -->
                <?php if (get_current_user_id() === (int) $post->post_author) : ?>
                    <div class="bcc-card-flag">
                        <?php echo bcc_get_status_badge($post->ID); ?>
                    </div>
                <?php endif; ?>

                <!-- Featured image -->
                <?php if (
                    class_exists('PeepSo')
                    && PeepSo::get_option('blogposts_profile_featured_image_enable')
                    && (
                        has_post_thumbnail($post)
                        || PeepSo::get_option('blogposts_profile_featured_image_enable_if_empty')
                    )
                ) : ?>
                    <div
                        class="ps-blogposts__post-image <?php echo esc_attr($image_position); ?>"
                        style="background-image: url('<?php echo esc_url(get_the_post_thumbnail_url($post, $image_size)); ?>');"
                    >
                        <a href="<?php echo esc_url(get_permalink($post)); ?>"></a>
                    </div>
                <?php endif; ?>

                <!-- Title -->
                <h2 class="ps-blogposts__post-title">
                    <a href="<?php echo esc_url(get_permalink($post)); ?>">
                        <?php echo esc_html(get_the_title($post)); ?>
                    </a>
                </h2>

                <!-- Meta -->
                <?php if (!empty($meta)) : ?>
                    <div class="ps-blogposts__post-meta">
                        <?php echo wp_kses_post($meta); ?>
                    </div>
                <?php endif; ?>

                <!-- Content -->
                <div class="ps-blogposts__post-content">
                    <?php
                    if (!empty($content)) {
                        echo wp_kses_post($content);
                    } elseif (has_excerpt()) {
                        the_excerpt();
                    } else {
                        echo wp_kses_post(
                            wp_trim_words(
                                strip_shortcodes(get_the_content()),
                                30
                            )
                        );
                    }
                    ?>
                </div>

                <!-- Actions -->
                <?php if (!empty($actions) && is_array($actions)) : ?>
                    <div class="ps-blogposts__post-actions">
                        <?php foreach ($actions as $action) :

                            if (empty($action['label']) || empty($action['url'])) {
                                continue;
                            }

                            $target = !empty($action['target'])
                                ? ' target="' . esc_attr($action['target']) . '"'
                                : '';
                            ?>
                            <a
                                href="<?php echo esc_url($action['url']); ?>"
                                class="ps-btn ps-btn--sm ps-btn--action"
                                <?php echo $target; ?>
                            >
                                <?php echo esc_html($action['label']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <?php
    wp_reset_postdata();
}
