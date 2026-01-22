<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_enqueue_scripts', function () {

    // Bail in admin
    if (is_admin()) {
        return;
    }

    // Only load if PeepSo exists
    if (!class_exists('PeepSo')) {
        return;
    }

    $base_url = plugin_dir_url(__FILE__) . '../assets/css/';

    // Variables (must load first)
    wp_enqueue_style(
        'bcc-vars',
        $base_url . 'bcc-vars.css',
        [],
        BCC_VERSION
    );

    // Layout
    wp_enqueue_style(
        'bcc-profile-layout',
        $base_url . 'profile-layout.css',
        ['bcc-vars'],
        BCC_VERSION
    );

    // Tabs
    wp_enqueue_style(
        'bcc-profile-tabs',
        $base_url . 'profile-tabs.css',
        ['bcc-profile-layout'],
        BCC_VERSION
    );

    // Actions
    wp_enqueue_style(
        'bcc-profile-actions',
        $base_url . 'profile-actions.css',
        ['bcc-profile-layout'],
        BCC_VERSION
    );

    // Cards (THIS IS THE ONE YOU NEED)
    wp_enqueue_style(
        'bcc-cards',
        $base_url . 'cards.css',
        ['bcc-profile-layout'],
        BCC_VERSION
    );

    // Forms
    wp_enqueue_style(
        'bcc-forms',
        $base_url . 'forms.css',
        ['bcc-vars'],
        BCC_VERSION
    );

}, 20);
