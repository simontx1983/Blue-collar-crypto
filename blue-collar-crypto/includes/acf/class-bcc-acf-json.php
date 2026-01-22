<?php
if (!defined('ABSPATH')) exit;

class BCC_ACF_JSON {
    public static function init() {
        // Save JSON
        add_filter('acf/settings/save_json', [__CLASS__, 'save_json_path']);

        // Load JSON
        add_filter('acf/settings/load_json', [__CLASS__, 'load_json_paths']);
    }

    public static function save_json_path($path) {
        return BCC_PATH . 'includes/acf/acf-json';
    }

    public static function load_json_paths($paths) {
        // Keep default path too, but add ours
        $paths[] = BCC_PATH . 'includes/acf/acf-json';
        return $paths;
    }
}
