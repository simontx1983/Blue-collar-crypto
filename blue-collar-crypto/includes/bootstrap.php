<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/helpers/query.php';
require_once __DIR__ . '/helpers/render.php';

add_action('wp_enqueue_scripts', function () {
    if (function_exists('acf_form_head')) {
        acf_form_head();
    }
});

// Helpers (logic & rendering)
require_once BCC_PATH . 'includes/helpers/render.php';
require_once BCC_PATH . 'includes/helpers/actions.php';


