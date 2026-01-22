<?php
if (!defined('ABSPATH')) exit;

class BCC_Blocks {
    public static function init() {
        add_action('acf/init', [__CLASS__, 'register_blocks']);
    }

    public static function register_blocks() {
        if (!function_exists('acf_register_block_type')) return;

        acf_register_block_type([
            'name'            => 'bcc-project-card',
            'title'           => 'BCC Project Card',
            'description'     => 'Displays a single Project card.',
            'category'        => 'widgets',
            'icon'            => 'index-card',
            'keywords'        => ['project', 'card', 'bcc'],
            'mode'            => 'preview',
            'render_template' => BCC_PATH . 'blocks/project-card/render.php',
            'enqueue_style'   => BCC_URL . 'blocks/project-card/style.css',
            'supports'        => [
                'align' => false,
                'jsx'   => true,
            ],
        ]);
    }
}
