<?php

namespace CodeConfig\IDB;

use CodeConfig\IDB\Utils\Helpers;

defined('ABSPATH') || exit('No direct script access allowed');

class Activation
{
    public static function init()
    {
        Helpers::checkPluginRequirements();
        $update = Update::getInstance();

        if ($update->isUpdateAvailable()) {
            $update->performUpdates();
        } else {
            self::setDefaultTable();
            self::setDefaultData();
            self::setDefaultSettings();
            self::setCustomCap();
            self::setRewriteRules();
        }

        self::checkPermalinkStructure();
    }

    private static function checkPermalinkStructure()
    {
        CodeConfig::getInstance()->checkPermalinkStructure();
    }

    private static function setDefaultTable()
    {
        global $wpdb;
        $wpdb->hide_errors();
        if (! function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $tables = ccpidbGetTablesDefinitions();

        foreach ($tables as $table) {
            dbDelta($table);
        }
    }

    private static function setDefaultData()
    {
        if (!get_option('ccpidb_version')) {
            update_option('ccpidb_version', CCPIDB_VERSION);
        }

        if (!get_option('ccpidb_install_time')) {
            update_option('ccpidb_install_time', current_time('mysql'));
        }

        if (!get_option('ccpidb_db_version')) {
            update_option('ccpidb_db_version', CCPIDB_DB_VERSION);
        }

        if (!get_option('ccpidb_first_installed_version')) {
            // TODO: Need to remove it's next version update
            $version = get_option('indbox_version', CCPIDB_VENDORS);
            update_option('ccpidb_first_installed_version', $version);
        }

        if (!get_option('ccpidb_encryption_key')) {
            update_option('ccpidb_encryption_key', wp_generate_uuid4());
        }

        set_transient('ccpidb-rating-notice-interval', 'off', 10 * DAY_IN_SECONDS);
    }

    private static function setDefaultSettings()
    {
        $default_settings = get_option(CCPIDB_OPTIONS_NAME, false);

        if (! $default_settings) {

            $default_settings = ccpidbGetDefaultSettings();

            update_option(CCPIDB_OPTIONS_NAME, $default_settings);
        }
    }

    private static function setCustomCap()
    {
        $role = get_role('administrator');
        if (!empty($role)) {
            $role->add_cap(CCPIDB_ACCESS_CAP);
        }
    }

    /**
     * Sets up rewrite rules for the plugin.
     *
     * This function adds a rewrite rule that matches the following pattern:
     * ^ccpidb/([^/]+)/([^/]+)/([^/]+)/([^/]+)$
     * The pattern matches the following example URL:
     * https://example.com/ccpidb/action/key/name/ext
     *
     * The matched groups are mapped to the following query parameters:
     * - action: $matches[1]
     * - key: $matches[2]
     * - name: $matches[3]
     * - ext: $matches[4]
     * The rewrite rule is added with a priority of 'top' to ensure it takes precedence over other rules.
     *
     * @since 1.3.0
     *
     * @return void
     */
    private static function setRewriteRules()
    {
        add_rewrite_rule(
            '^ccpidb/([^/]+)/([^/]+)/([^/]+)/([^/]+)$',
            'index.php?ccpidb-action=$matches[1]&ccpidb-key=$matches[2]&ccpidb-name=$matches[3]&ccpidb-ext=$matches[4]',
            'top'
        );
        add_rewrite_rule(
            '^ccpidb/([^/]+)/([^/]+)/([^/]+)\.([^/]+)$',
            'index.php?ccpidb-action=$matches[1]&ccpidb-key=$matches[2]&ccpidb-name=$matches[3]&ccpidb-ext=$matches[4]',
            'top'
        );

        flush_rewrite_rules();
    }
}
