<?php

namespace CodeConfig\IDB;

use CodeConfig\IDB\Models\Shortcode as ModelsShortcode;
use CodeConfig\IDB\Utils\Singleton;
use function defined;
use function in_array;
use function intval;
defined( 'ABSPATH' ) || exit( 'No direct script access allowed' );
class Shortcode {
    use Singleton;
    /**
     * Shortcode instance
     * @var ModelsShortcode
     */
    private $scModel;

    private $return = 'string';

    private $integration = null;

    public static $modulesList = [
        'gallery',
        'file-list',
        'embed-documents',
        'search-box'
    ];

    public function __construct() {
        if ( empty( $this->scModel ) ) {
            $this->scModel = ModelsShortcode::getInstance();
        }
    }

    public static function getModulesList() {
        return self::$modulesList;
    }

    public function doHooks() {
        add_shortcode( 'integrate_dropbox', [$this, 'render'] );
    }

    public function render( $atts = [] ) {
        $atts = shortcode_atts( [
            'id'          => 0,
            'return'      => 'string',
            'integration' => '',
        ], $atts );
        $this->return = ( $atts['return'] === 'array' ? 'array' : 'string' );
        $this->integration = sanitize_text_field( wp_unslash( $atts['integration'] ?? '' ) );
        $id = intval( $atts['id'] );
        wp_enqueue_style( 'ccpidb-common' );
        if ( empty( $id ) ) {
            if ( !current_user_can( 'manage_options' ) ) {
                return;
            }
            $args = [
                'title'       => __( 'Please provide a valid ID', 'integrate-dropbox' ),
                'description' => 'Please provide a valid ID. Module ID not found.',
                'card_status' => 'error',
                'icon'        => 'report',
            ];
            ob_start();
            ccpidbGetTemplate( 'notice-card/notice-card-common', $args );
            return ob_get_clean();
        }
        $shortcode = $this->scModel->get( $id );
        if ( empty( $shortcode ) || is_wp_error( $shortcode ) ) {
            if ( !current_user_can( 'manage_options' ) ) {
                return;
            }
            $message = ( is_wp_error( $shortcode ) ? $shortcode->get_error_message() : __( 'Module not found', 'integrate-dropbox' ) );
            $args = [
                'title'       => "#{$id} - {$message}",
                'card_status' => 'error',
                'icon'        => 'error',
            ];
            ob_start();
            ccpidbGetTemplate( 'notice-card/notice-card-common', $args );
            return ob_get_clean();
        }
        $proModules = ccpidbGetModules( 'pro' );
        if ( in_array( $shortcode['type'], wp_list_pluck( $proModules, 'id' ) ) ) {
            if ( !current_user_can( 'manage_options' ) ) {
                return;
            }
            $args = [
                'title'          => __( 'This Module is a Pro Module', 'integrate-dropbox' ),
                'description'    => 'Need to upgrade to get access to this Module.',
                'card_status'    => 'warning',
                'icon'           => 'report',
                'primary_button' => [
                    'icon'   => 'crown',
                    'title'  => 'Upgrade Now',
                    'url'    => ccpidb_fs()->get_upgrade_url(),
                    'target' => true,
                ],
            ];
            ob_start();
            ccpidbGetTemplate( 'notice-card/notice-card-common', $args );
            return ob_get_clean();
        }
        if ( is_wp_error( $shortcode ) ) {
            if ( !current_user_can( 'manage_options' ) ) {
                return;
            }
            $message = $shortcode->get_error_message();
            $args = [
                'title'       => $message,
                'card_status' => 'warning',
                'icon'        => 'error',
            ];
            ob_start();
            ccpidbGetTemplate( 'notice-card/notice-card-common', $args );
            return ob_get_clean();
        }
        if ( empty( $shortcode ) ) {
            if ( !current_user_can( 'manage_options' ) ) {
                return;
            }
            $message = __( 'Module not found', 'integrate-dropbox' );
            $args = [
                'title'       => "#{$id} - {$message}",
                'card_status' => 'error',
                'icon'        => 'error',
            ];
            ob_start();
            ccpidbGetTemplate( 'notice-card/notice-card-common', $args );
            return ob_get_clean();
        }
        if ( empty( $shortcode['status'] ) || isset( $shortcode['status'] ) && $shortcode['status'] !== 'active' ) {
            if ( !current_user_can( 'manage_options' ) ) {
                return;
            }
            $message = __( 'Shortcode is disabled', 'integrate-dropbox' );
            $args = [
                'title'          => "#{$id} - {$message}",
                'description'    => __( 'Please enable this Module from Module Builder', 'integrate-dropbox' ),
                'card_status'    => 'error',
                'icon'           => 'sentiment_very_dissatisfied',
                'primary_button' => [
                    'title'  => 'Enable Shortcode',
                    'url'    => admin_url( "admin.php?page=integrate-dropbox#/module-builder/{$id}/modules" ),
                    'target' => true,
                ],
            ];
            ob_start();
            ccpidbGetTemplate( 'notice-card/notice-card-common', $args );
            return ob_get_clean();
        }
        if ( !empty( $this->integration ) ) {
            $integration = str_replace( '*', '', $this->integration );
            if ( empty( $shortcode['integration'] ) || isset( $shortcode['integration'] ) && $shortcode['integration'] !== $integration ) {
                if ( !current_user_can( 'manage_options' ) ) {
                    return;
                }
                $title = __( 'Integration Mismatch', 'integrate-dropbox' );
                $message = __( 'This Module is not compatible with this Integration', 'integrate-dropbox' );
                if ( $integration === 'contactForm7' && $shortcode['type'] !== 'file-upload' ) {
                    $message = __( 'This module is not compatible with this integration. Contact Form 7 only supports File Upload modules that are created using the Contact Form 7 Module Builder.', 'integrate-dropbox' );
                }
                $args = [
                    'title'       => "#{$id} - {$title}",
                    'description' => $message,
                    'card_status' => 'warning',
                    'icon'        => 'report',
                ];
                ob_start();
                ccpidbGetTemplate( 'notice-card/notice-card-common', $args );
                return ob_get_clean();
            }
        }
        $data = maybe_unserialize( $shortcode['data'] ?? '' );
        if ( empty( $data ) ) {
            if ( !current_user_can( 'manage_options' ) ) {
                return;
            }
            $message = __( 'No data available for this Module', 'integrate-dropbox' );
            $args = [
                'title'       => "#{$id} - {$message}",
                'card_status' => 'error',
                'icon'        => 'sentiment_dissatisfied',
            ];
            ob_start();
            ccpidbGetTemplate( 'notice-card/notice-card-common', $args );
            return ob_get_clean();
        }
        $shortcode['data'] = $data;
        return $this->renderShortcode( $id, $shortcode );
    }

    private function renderShortcode( $id, $data ) {
        $type = $data['type'] ?? '';
        if ( empty( $type ) ) {
            if ( !current_user_can( 'manage_options' ) ) {
                return;
            }
            $message = __( 'Type not given for this Module', 'integrate-dropbox' );
            $args = [
                'title'       => "#{$id} - {$message}",
                'card_status' => 'warning',
                'icon'        => 'warning',
            ];
            ob_start();
            ccpidbGetTemplate( 'notice-card/notice-card-common', $args );
            return ob_get_clean();
        }
        if ( !isset( $data['data'] ) || empty( $data['data'] ) ) {
            if ( !current_user_can( 'manage_options' ) ) {
                return;
            }
            $message = __( 'No data provided for this Module', 'integrate-dropbox' );
            $args = [
                'title'       => "#{$id} - {$message}",
                'card_status' => 'warning',
                'icon'        => 'sentiment_very_dissatisfied',
            ];
            ob_start();
            ccpidbGetTemplate( 'notice-card/notice-card-common', $args );
            return ob_get_clean();
        }
        $status = 'public';
        if ( isset( $data['data']['permissions']['passwordProtect']['password'] ) ) {
            unset($data['data']['permissions']['passwordProtect']['password']);
        }
        $object_key = "ccpidb_{$id}";
        $enqueueHandle = "ccpidb-{$type}";
        $enqueue = Enqueue::getInstance();
        $enqueue->common_scripts( $object_key, 'frontend' );
        $enqueue->add( 'shared', 'js', [$enqueueHandle] );
        $enqueue->add(
            $type,
            'css',
            [],
            [
                'folder' => 'css/frontend',
            ]
        );
        $escaped_id = absint( $id );
        $escaped_status = esc_attr( $status );
        $escaped_enqueue_handle = esc_attr( $enqueueHandle );
        $escaped_object_key = esc_js( $object_key );
        $postId = get_the_ID();
        $html = sprintf(
            '<div data-post_id="%d" data-id="ccpidb_%d" data-status="%s" id="ccpidb-module-%s" class="ccpidb-top-level-wrapper %s"></div>',
            $postId,
            $escaped_id,
            $escaped_status,
            $escaped_id,
            $escaped_enqueue_handle
        );
        if ( $this->return === 'array' ) {
            return [
                'id'             => $escaped_id,
                'status'         => $escaped_status,
                'data_id'        => "ccpidb_{$escaped_id}",
                'element_id'     => "ccpidb-module-{$escaped_id}",
                'enqueue_handle' => $escaped_enqueue_handle,
                'type'           => $type,
                'data'           => $data,
                'html'           => $html,
            ];
        }
        $json_data = wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
        if ( false === $json_data ) {
            $json_data = '{}';
        }
        $html .= sprintf( '<script>window.%s = %s;</script>', $escaped_object_key, $json_data );
        return $html;
    }

}
