<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Projects → Overview → Edit
 * Frontend edit screen for a single project
 */

// --------------------------------------------------
// Context
// --------------------------------------------------
$current_user_id = (int) get_current_user_id();
$project_id      = isset($_GET['project_id']) ? (int) $_GET['project_id'] : 0;

// --------------------------------------------------
// Validate project
// --------------------------------------------------
if (!$project_id || get_post_type($project_id) !== 'project') {
    echo '<div class="ps-alert ps-alert--error"><p>Invalid project.</p></div>';
    return;
}

// --------------------------------------------------
// Ownership check
// --------------------------------------------------
$project_owner_raw = get_field('project_owner', $project_id);
$project_owner_id  = null;

if (is_array($project_owner_raw) && isset($project_owner_raw['ID'])) {
    $project_owner_id = (int) $project_owner_raw['ID'];
} elseif (is_object($project_owner_raw) && isset($project_owner_raw->ID)) {
    $project_owner_id = (int) $project_owner_raw->ID;
} elseif (is_numeric($project_owner_raw)) {
    $project_owner_id = (int) $project_owner_raw;
}

if ($project_owner_id !== $current_user_id) {
    echo '<div class="ps-alert ps-alert--error"><p>You do not have permission to edit this project.</p></div>';
    return;
}

// --------------------------------------------------
// Header config
// --------------------------------------------------

$can_create   = false;
$create_label = '';
$create_url   = '';

// Render overview header
include BCC_PATH . 'templates/profile/partials/headers/overview-header.php';

// --------------------------------------------------
// Render ACF edit form
// --------------------------------------------------
?>

<div class="bcc-project-edit">

    <?php
    acf_form([
        'id'             => 'edit-project',
        'post_id'        => $project_id,
        'form'           => true,
        'post_title'     => true,
        'post_content'   => true,
        'submit_value'   => 'Update Project',
        'updated_message'=> 'Project updated successfully.',
        'return'         => PeepSoUser::get_instance($current_user_id)->get_profileurl() . 'projects/overview/view',
    ]);
    ?>

</div>
