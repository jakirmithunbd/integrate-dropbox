<?php

namespace CodeConfig\IDB;

defined( 'ABSPATH' ) or exit( 'Hey, what are you doing here? You silly human!' );
/*
 * Plugin Name:       File Manager for Dropbox
 * Plugin URI:        https://codeconfig.dev/integrate-dropbox/
 * Description:       Integrate Dropbox: user-friendly WordPress plugin beautifully displays Dropbox files on posts, pages, & products.
 * Version:           1.3.10
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            CodeConfig
 * Author URI:        https://codeconfig.dev/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       integrate-dropbox
 * Domain Path:       /languages
 */
if ( function_exists( '\\CodeConfig\\IDB\\ccpidb_fs' ) ) {
    ccpidb_fs()->set_basename( false, __FILE__ );
} else {
    if ( !function_exists( '\\CodeConfig\\IDB\\ccpidb_fs' ) ) {
        function ccpidb_fs() {
            global $ccpidb_fs;
            if ( !isset( $ccpidb_fs ) ) {
                if ( !class_exists( 'Freemius' ) ) {
                    require_once dirname( __FILE__ ) . '/freemius/start.php';
                }
                $ccpidb_fs = fs_dynamic_init( [
                    'id'               => '15531',
                    'slug'             => 'integrate-dropbox',
                    'premium_slug'     => 'integrate-dropbox-pro',
                    'type'             => 'plugin',
                    'public_key'       => 'pk_7b9c0e876c395a764dda52ddb28cd',
                    'is_premium'       => false,
                    'premium_suffix'   => 'PRO',
                    'has_addons'       => false,
                    'has_paid_plans'   => true,
                    'trial'            => [
                        'days'               => 7,
                        'is_require_payment' => true,
                    ],
                    'menu'             => [
                        'slug' => 'integrate-dropbox',
                    ],
                    'is_live'          => true,
                    'is_org_compliant' => true,
                ] );
            }
            return $ccpidb_fs;
        }

        ccpidb_fs();
        do_action( 'ccpidb_fs_loaded' );

        if ( ! ccpidb_fs()->is_premium() ) {
            $puc_path = plugin_dir_path( __FILE__ ) . 'plugin-update-checker/plugin-update-checker.php';
            if ( file_exists( $puc_path ) ) {
                require $puc_path;

                $update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
                    'https://github.com/jakirmithunbd/integrate-dropbox/',
                    __FILE__,
                    'integrate-dropbox'
                );

                $update_checker->getVcsApi()->enableReleaseAssets();
            }
        }
    }
    define( 'CCPIDB_FILE', __FILE__ );
    require_once plugin_dir_path( CCPIDB_FILE ) . 'core/config.php';
    require_once plugin_dir_path( CCPIDB_FILE ) . 'core/functions.php';
    $ccpidb_include_files = ['Autoload'];
    foreach ( $ccpidb_include_files as $ccpidb_include_file ) {
        $ccpidb_include_file = CCPIDB_INCLUDES . '/' . $ccpidb_include_file . '.php';
        if ( file_exists( $ccpidb_include_file ) ) {
            require_once $ccpidb_include_file;
        }
    }
    Autoload::register();
    Update::getInstance()->checkUpdates();
    CodeConfig::getInstance();
}