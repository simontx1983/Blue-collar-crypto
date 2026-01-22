<?php
if (!defined('ABSPATH')) exit;

// Only show if user can edit
if (!$can_edit) {
    echo '<div class="ps-alert ps-alert--warning"><p>You cannot create projects for this user.</p></div>';
    return;
}
?>

<h3 class="ps-heading">Create New Project</h3>

<?php
// Use ACFE form or regular ACF form
if (function_exists('acfe_form')) {
    acfe_form('new-project');
} elseif (function_exists('acf_form')) {
    acf_form([
        'post_id' => 'new_post',
        'new_post' => [
            'post_type' => 'project',
            'post_status' => 'draft'
        ],
        'submit_value' => 'Create Project',
        'updated_message' => 'Project created successfully'
    ]);
} else {
    echo '<p>Project creation form is not available.</p>';
}
?>