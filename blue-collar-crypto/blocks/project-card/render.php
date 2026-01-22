<?php
if (!defined('ABSPATH')) exit;

global $post;
$project_id = get_the_ID();

if (!$project_id) {
    echo '<div class="bcc-card bcc-card--error">No project context found.</div>';
    return;
}

$title   = get_the_title($project_id);
$permalink = get_permalink($project_id);
$thumb   = get_the_post_thumbnail_url($project_id, 'medium');

// Example ACF fields (rename to your real keys)
$ecosystem = get_field('ecosystem', $project_id);
$status    = get_field('project_status', $project_id);

?>
<article class="bcc-card bcc-project-card">
    <a class="bcc-card__link" href="<?php echo esc_url($permalink); ?>">
        <?php if ($thumb): ?>
            <div class="bcc-card__media" style="background-image:url('<?php echo esc_url($thumb); ?>');"></div>
        <?php endif; ?>

        <div class="bcc-card__body">
            <h3 class="bcc-card__title"><?php echo esc_html($title); ?></h3>

            <?php if ($status): ?>
                <div class="bcc-card__meta">
                    <span class="bcc-badge"><?php echo esc_html($status); ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($ecosystem)): ?>
                <div class="bcc-card__meta">
                    <span class="bcc-meta">
                        <?php
                        // ecosystem might be term objects, array, or string depending on your field type.
                        if (is_array($ecosystem)) {
                            $names = [];
                            foreach ($ecosystem as $item) {
                                $names[] = is_object($item) && isset($item->name) ? $item->name : (string) $item;
                            }
                            echo esc_html(implode(', ', $names));
                        } else {
                            echo esc_html((string) $ecosystem);
                        }
                        ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>
    </a>
</article>
