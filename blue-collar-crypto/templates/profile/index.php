<?php
if (!defined('ABSPATH')) exit;

// Get user context
$viewed_user_id = bcc_get_current_user_id();
$current_user_id = get_current_user_id();
$can_edit = ($viewed_user_id && $current_user_id === $viewed_user_id);

// Get current section/mode
$segment = PeepSoUrlSegments::get_instance();
$section = $segment->get(3) ?: 'overview';
$mode = $segment->get(4) ?: 'view';

// Validate
$sections = ['overview', 'nft', 'validators', 'builders'];
$section = in_array($section, $sections) ? $section : 'overview';
$mode = in_array($mode, ['view', 'create', 'edit']) ? $mode : 'view';

// Redirect create mode if not allowed
if ($mode === 'create' && !$can_edit) {
    $mode = 'view';
}

// Display PeepSo navbar and profile focus
PeepSoTemplate::exec_template('general', 'navbar');
PeepSoTemplate::exec_template('profile', 'focus', ['current' => 'projects']);

// Include navigation
include __DIR__ . '/nav.php';
?>

<div class="ps-page ps-page--profile">
    <div class="ps-card ps-spacing">
        <?php
        // Load section template
        $template_path = __DIR__ . "/{$section}/{$mode}.php";
        if (file_exists($template_path)) {
            include $template_path;
        } else {
            echo '<div class="ps-alert ps-alert--error"><p>Template not found.</p></div>';
        }
        ?>
    </div>
</div>