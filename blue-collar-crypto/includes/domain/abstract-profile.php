<?php
/**
 * Abstract Domain Object: Profile
 *
 * Shared base for Validator, NFT Creator, Builder, etc.
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class BCC_AbstractProfile {

    protected int $post_id;

    /**
     * Constructor
     */
    public function __construct(int $post_id) {
        $this->post_id = $post_id;
    }

    /**
     * Core identity
     */
    public function id(): int {
        return $this->post_id;
    }

    public function title(): string {
        return get_the_title($this->post_id);
    }

    public function permalink(): string {
        return get_permalink($this->post_id);
    }

    /**
     * Media
     */
    public function featured_image(string $size = 'medium'): string {
        return get_the_post_thumbnail_url($this->post_id, $size) ?: '';
    }

    /**
     * Meta helpers
     */
    protected function field(string $key) {
        return get_field($key, $this->post_id);
    }

    protected function meta(string $key) {
        return get_post_meta($this->post_id, $key, true);
    }
}
