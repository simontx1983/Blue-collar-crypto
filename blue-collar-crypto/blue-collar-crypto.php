<?php
/**
 * Plugin Name: Blue Collar Crypto Projects
 * Description: Adds Projects and Business tabs to PeepSo profiles
 * Version: 1.0.0
 * Author: Phillip Simon
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin Constants
 */
define('BCC_PATH', plugin_dir_path(__FILE__));
define('BCC_URL', plugin_dir_url(__FILE__));
define('BCC_VERSION', '1.0.0');

/**
 * Core Loader (CLASSES FIRST)
 */
require_once BCC_PATH . 'includes/loader.php';

/**
 * Bootstrap / Setup
 */
require_once BCC_PATH . 'includes/bootstrap.php';
require_once BCC_PATH . 'includes/enqueue.php';
require_once BCC_PATH . 'includes/actions/post-actions.php';

/**
 * ACF & Blocks
 */
require_once BCC_PATH . 'includes/acf/class-bcc-acf-json.php';
require_once BCC_PATH . 'includes/acf/class-bcc-blocks.php';

BCC_ACF_JSON::init();
BCC_Blocks::init();

/**
 * PeepSo Tabs
 */
add_filter('peepso_navigation_profile', function ($tabs) {
    $tabs['projects'] = [
        'href'  => 'projects',
        'label' => __('Projects', 'blue-collar-crypto'),
        'icon'  => 'dashicons-portfolio',
    ];
    return $tabs;
});

/**
 * Projects Tab Content
 */
add_action('peepso_profile_segment_projects', function () {
    include BCC_PATH . 'templates/profile/index.php';
});

/**
 * Disable Gutenberg editor for Project CPT
 */
add_filter('use_block_editor_for_post_type', function ($use, $post_type) {
    return $post_type === 'project' ? false : $use;
}, 10, 2);
