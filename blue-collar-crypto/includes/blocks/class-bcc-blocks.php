<?php
/**
 * Block Registration Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Blocks
{
    /**
     * Bootstrap block registration
     */
    public static function init(): void
    {
        add_action('init', [__CLASS__, 'register_blocks']);
    }

    /**
     * Register all custom blocks
     */
    public static function register_blocks(): void
    {
        $blocks_dir = BCC_PATH . 'includes/blocks/';

        // Register blocks that use block.json
        $block_folders = [
            'validators',
            'projects',
            // add more block folders here
        ];

        foreach ($block_folders as $block) {
            $block_path = $blocks_dir . $block;

            if (file_exists($block_path . '/block.json')) {
                register_block_type($block_path);
            }
        }
    }
}
