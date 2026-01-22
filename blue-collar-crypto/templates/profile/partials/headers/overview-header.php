<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Overview Header Partial
 *
 * Expected variables (passed in before include):
 * ------------------------------------------------
 * $title        (string)  – Section title (e.g. "Projects")
 * $create_label (string)  – Button label (e.g. "Create Project")
 * $create_url   (string)  – URL for create action
 * $can_create   (bool)    – Whether current user can create
 */

// Defensive defaults
$title        = isset($title) ? $title : '';
$create_label = isset($create_label) ? $create_label : '';
$create_url   = isset($create_url) ? $create_url : '';
$can_create   = isset($can_create) ? (bool) $can_create : false;
?>

<div class="bcc-overview-header">

    <div class="bcc-overview-header__left">
        <h2 class="bcc-overview-header__title">
            <?php echo esc_html($title); ?>
        </h2>
    </div>
<?php
// Optional filters
$filters = isset($filters) && is_array($filters) ? $filters : [];
?>

<div class="bcc-overview-header">

    <div class="bcc-overview-header__left">
        <h2 class="bcc-overview-header__title">
            <?php echo esc_html($title); ?>
        </h2>

        <?php if (!empty($filters)) : ?>
            <form method="get" class="bcc-overview-filters">

                <?php foreach ($filters as $filter) : ?>
                    <?php
                    $name    = $filter['name'];
                    $label   = $filter['label'];
                    $options = $filter['options'];
                    $current = isset($_GET[$name]) ? sanitize_text_field($_GET[$name]) : '';
                    ?>

                    <label>
                        <span class="screen-reader-text"><?php echo esc_html($label); ?></span>
                        <select name="<?php echo esc_attr($name); ?>" onchange="this.form.submit()">
                            <option value=""><?php echo esc_html($label); ?></option>

                            <?php foreach ($options as $value => $text) : ?>
                                <option value="<?php echo esc_attr($value); ?>"
                                    <?php selected($current, $value); ?>>
                                    <?php echo esc_html($text); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                <?php endforeach; ?>

            </form>
        <?php endif; ?>

    </div>

    <?php if ($can_create && $create_url && $create_label) : ?>
        <div class="bcc-overview-header__right">
            <a href="<?php echo esc_url($create_url); ?>"
               class="ps-btn ps-btn--app ps-btn--sm">
                <?php echo esc_html($create_label); ?>
            </a>
        </div>
    <?php endif; ?>

</div>

    <?php if ($can_create && $create_url && $create_label) : ?>
        <div class="bcc-overview-header__right">
            <a href="<?php echo esc_url($create_url); ?>"
               class="ps-btn ps-btn--app ps-btn--sm">
                <?php echo esc_html($create_label); ?>
            </a>
        </div>
    <?php endif; ?>

</div>
