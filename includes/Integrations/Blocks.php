<?php

namespace CodeConfig\IDB\Integrations;

use CodeConfig\IDB\Shortcode;
use CodeConfig\IDB\Utils\Singleton;
defined( 'ABSPATH' ) or exit;
class Blocks extends BaseIntegration {
    use Singleton;
    public function __construct() {
        parent::__construct( 'gutenberg', 'Gutenberg Blocks' );
    }

    public function addProStyles() {
        ?>
        <style>
            .editor-block-list-item-ccpidb-file-browser:before,
            .editor-block-list-item-ccpidb-slider-carousel:before {
                content: '\f160';
                font-family: 'dashicons', serif;
                font-size: 20px;
                color: #7badff;
                position: absolute;
                top: 5px;
                right: 5px;
            }
        </style>
<?php 
    }

    public function init( string $id, array $integration ) : void {
        add_action( 'init', [$this, 'registerGutenbergBlocks'] );
        add_filter(
            'block_categories_all',
            [$this, 'blockCategory'],
            10,
            2
        );
    }

    public function registerGutenbergBlocks() {
        $blocks = [
            'file-browser',
            'media-player',
            'gallery',
            'slider',
            'embed-documents',
            'search-box',
            'file-list',
            'shortcode'
        ];
        foreach ( $blocks as $block ) {
            register_block_type( CCPIDB_PATH . 'assets/js/blocks/' . $block, [
                'render_callback' => [$this, 'renderBlocks'],
            ] );
        }
    }

    public function renderBlocks( $attributes, $content, $block ) {
        if ( empty( $attributes['id'] ) ) {
            return '';
        }
        $html = Shortcode::getInstance()->render( [
            'id'   => $attributes['id'],
            'type' => $attributes['type'] ?? 'file-browser',
        ] );
        return $html;
    }

    public function blockCategory( $categories ) {
        array_unshift( $categories, [
            'slug'  => 'integrate-dropbox',
            'title' => __( 'Integrate Dropbox', 'integrate-dropbox' ),
            'icon'  => 'cloud',
        ] );
        return $categories;
    }

}
