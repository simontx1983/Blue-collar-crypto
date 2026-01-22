<?php
/**
 * URL Helpers
 *
 * Utilities for working with URLs and query parameters.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get a query parameter safely
 *
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */
function bcc_get_query_arg(string $key, $default = null)
{
    return isset($_GET[$key])
        ? sanitize_text_field(wp_unslash($_GET[$key]))
        : $default;
}

/**
 * Build a URL with merged query arguments
 *
 * @param string $base_url
 * @param array  $args
 * @return string
 */
function bcc_build_url(string $base_url, array $args = []): string
{
    return esc_url(add_query_arg($args, $base_url));
}

/**
 * Preserve existing query parameters
 *
 * Useful for filter forms (network, sort, pagination)
 *
 * @param array $exclude Keys to exclude
 * @return string HTML hidden inputs
 */
function bcc_preserve_query_inputs(array $exclude = []): string
{
    $html = '';

    foreach ($_GET as $key => $value) {
        if (in_array($key, $exclude, true)) {
            continue;
        }

        $html .= sprintf(
            '<input type="hidden" name="%s" value="%s">',
            esc_attr($key),
            esc_attr(sanitize_text_field(wp_unslash($value)))
        );
    }

    return $html;
}

/**
 * Check if a specific query arg is active
 *
 * @param string $key
 * @param mixed  $value
 * @return bool
 */
function bcc_is_query_active(string $key, $value): bool
{
    return isset($_GET[$key]) && (string) $_GET[$key] === (string) $value;
}
