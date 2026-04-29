<?php

namespace CodeConfig\IDB;

use CodeConfig\IDB\API\ApiRegistry;
use CodeConfig\IDB\App\Authorization;
use CodeConfig\IDB\Integrations\Blocks;
use CodeConfig\IDB\Integrations\Elementor;
use CodeConfig\IDB\Integrations\Forms\ContactForm7;
use CodeConfig\IDB\Shortcode\Locations;
use CodeConfig\IDB\Utils\Helpers;
defined( 'ABSPATH' ) || exit( 'No direct script access allowed' );
use CodeConfig\IDB\Utils\Singleton;
class CodeConfig {
    use Singleton;
    public function __construct() {
        $this->init();
        $this->doHooks();
    }

    public function checkPermalinkStructure() {
        global $wp_rewrite;
        if ( empty( $wp_rewrite->permalink_structure ) ) {
            $message = sprintf(
                '<strong>%s:</strong> %s <a href="%s">%s</a> %s',
                esc_html__( 'Integrate Dropbox', 'integrate-dropbox' ),
                esc_html__( 'This plugin requires pretty permalinks to be enabled for full functionality.', 'integrate-dropbox' ),
                admin_url( 'options-permalink.php' ),
                esc_html__( 'Please update your permalink settings', 'integrate-dropbox' ),
                esc_html__( 'to any structure except "Plain".', 'integrate-dropbox' )
            );
            AdminNotice::getInstance()->addNotice(
                'ccpidb_permalink_notice',
                $message,
                [],
                'warning',
                true,
                'admin_notices',
                []
            );
        }
    }

    public function adminNotices() {
        $is_running = (bool) get_transient( 'ccpidb_generate_file_media_info_running' );
        if ( empty( $is_running ) ) {
            return;
        }
        $message = sprintf( '<strong>%s:</strong> %s', esc_html__( 'Integrate Dropbox', 'integrate-dropbox' ), esc_html__( 'Media information generation is in progress. You will be notified once it is complete.', 'integrate-dropbox' ) );
        AdminNotice::getInstance()->addNotice(
            'ccpidb-media-info-notice',
            $message,
            [],
            'info',
            true,
            'admin_notices',
            []
        );
    }

    private function init() {
        register_activation_hook( CCPIDB_FILE, fn() => $this->handleNetworkActivation( 'activate' ) );
        register_deactivation_hook( CCPIDB_FILE, fn() => $this->handleNetworkActivation( 'deactivate' ) );
        Content::getInstance();
        Admin::getInstance();
        Schedule::getInstance();
        Enqueue::getInstance();
        Shortcode::getInstance();
        Locations::getInstance();
        // Integrations
        Blocks::getInstance();
        Elementor::getInstance();
        ContactForm7::getInstance();
        ApiRegistry::getInstance();
    }

    /**
     * Adds hooks to the WordPress hooks system.
     *
     * @return void
     */
    private function doHooks() {
        add_action( 'wp_ajax_nopriv_indbox_authorization', [$this, 'doingAuth'] );
        add_action( 'wp_ajax_indbox_authorization', [$this, 'doingAuth'] );
        add_action( 'admin_notices', [$this, 'adminNotices'] );
        add_action( 'ccpidb_generate_file_media_info_event', [Helpers::class, 'generateFileMediaInfo'] );
        add_filter(
            'plugin_row_meta',
            [$this, 'pluginRowMeta'],
            10,
            2
        );
        add_filter( 'plugin_action_links_' . plugin_basename( CCPIDB_FILE ), [$this, 'actionLinks'] );
        add_action( 'init', [$this, 'registerRewriteRules'] );
        add_filter( 'allowed_redirect_hosts', [$this, 'addAllowedRedirectHosts'] );
        add_action( 'admin_init', [$this, 'checkPermalinkStructure'] );
        // Multisite support
        add_action( 'wp_insert_site', fn( $newSite ) => $this->activationNewSite( $newSite ) );
    }

    public function pluginRowMeta( $links, $file ) {
        if ( $file == plugin_basename( CCPIDB_FILE ) ) {
            $links[] = sprintf( '<a target="_blank" href="%s">%s</a>', CCPIDB_DOCUMENTATION_URL, esc_html__( 'Docs & FAQs', 'integrate-dropbox' ) );
        }
        return $links;
    }

    public function actionLinks( $links ) {
        $links[] = sprintf( '<a target="_blank" href="%s">%s</a>', admin_url( 'admin.php?page=integrate-dropbox#/settings' ), esc_html__( 'Settings', 'integrate-dropbox' ) );
        return $links;
    }

    public function doingAuth() {
        if ( !wp_verify_nonce( explode( '|', sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) ) )[1] ?? '', 'ccpidb-auth-nonce' ) ) {
            wp_die( esc_html__( 'Invalid authorization request.', 'integrate-dropbox' ) );
        }
        $state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
        if ( empty( $state ) ) {
            wp_die( esc_html__( 'Invalid authorization request.', 'integrate-dropbox' ) );
        }
        // $explodeState = explode('|', $state);
        // $nonce = $explodeState[1] ?? '';
        $code = sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) );
        if ( empty( $code ) ) {
            wp_die( esc_html__( 'Authorization code is missing.', 'integrate-dropbox' ) );
        }
        Authorization::getInstance()->doingAuth( $code, $state );
    }

    /**
     * Registers rewrite rules for the plugin.
     *
     * Registers a rewrite rule that matches the following pattern:
     * ^ccpidb/([^/]+)/([^/]+)/([^/]+)/([^/]+)$
     *
     * The pattern matches the following example URL:
     * https://example.com/ccpigd/action/key/name/ext
     *
     * The matched groups are mapped to the following query parameters:
     * - action: $matches[1]
     * - key: $matches[2]
     * - name: $matches[3]
     * - ext: $matches[4]
     * The rewrite rule is added with a priority of 'top' to ensure it takes precedence over other rules.
     * @since 1.2.0 update 1.3.2
     *
     * @return void
     */
    public function registerRewriteRules() {
        add_rewrite_rule( '^ccpidb/([^/]+)/([^/]+)/([^/]+)/([^/]+)$', 'index.php?ccpidb-action=$matches[1]&ccpidb-key=$matches[2]&ccpidb-name=$matches[3]&ccpidb-ext=$matches[4]', 'top' );
        add_rewrite_rule( '^ccpidb/([^/]+)/([^/]+)/([^/]+)\\.([^/]+)$', 'index.php?ccpidb-action=$matches[1]&ccpidb-key=$matches[2]&ccpidb-name=$matches[3]&ccpidb-ext=$matches[4]', 'top' );
    }

    public function addAllowedRedirectHosts( $hosts ) {
        $allowedHosts = ['www.dropbox.com', 'view.officeapps.live.com'];
        $hosts = array_merge( $hosts, $allowedHosts );
        return $hosts;
    }

    private function activationNewSite( $newSite ) {
        switch_to_blog( $newSite->blog_id );
        Activation::init();
        restore_current_blog();
    }

    /**
     * Handle plugin activation/deactivation across single & multisite
     *
     * @param string $action activate|deactivate
     */
    private function handleNetworkActivation( string $action ) : void {
        $isActivate = $action === 'activate';
        $isDeactivate = $action === 'deactivate';
        if ( !$isActivate && !$isDeactivate ) {
            return;
        }
        // Single site
        if ( !is_multisite() ) {
            ( $isActivate ? Activation::init() : Deactivation::init() );
            return;
        }
        // Multisite
        global $wpdb;
        $originalBlogId = (int) $wpdb->blogid;
        $activatedSites = [];
        foreach ( get_sites( [
            'fields' => 'ids',
        ] ) as $blogId ) {
            switch_to_blog( $blogId );
            if ( $isActivate ) {
                Activation::init();
                $activatedSites[] = $blogId;
            } else {
                Deactivation::init();
            }
        }
        switch_to_blog( $originalBlogId );
        if ( $isActivate ) {
            update_site_option( 'ccpidb_activated', $activatedSites );
        }
    }

}
