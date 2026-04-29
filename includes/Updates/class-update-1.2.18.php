<?php

namespace CodeConfig\IntegrateDropbox;

use CodeConfig\IDB\Utils\Singleton;
use Exception;
use WP_Error;

defined('ABSPATH') or exit('Hey, what are you doing here? You silly human!');


class Update_1_2_18
{
    use Singleton;

    public function run_update()
    {

        try {
            $this->migration_options();
            $this->migration_tables();

            return '1.2.18';
        } catch (Exception $th) {
            return new WP_Error(400, 'Update to version 1.2.18 failed: ' . $th->getMessage());
        }
    }

    private function migration_options()
    {
        $options = [
            'integrate_dropbox_settings'            => 'indbox_settings',
            'integrate_dropbox_access_tokens'       => 'indbox_access_tokens',
            'integrate_dropbox_install_time'        => 'indbox_install_time',
            'integrate_dropbox_version'             => 'indbox_version',
        ];

        foreach ($options as $old_option => $new_option) {
            $value = get_option($old_option);
            if ($value !== false) {
                update_option($new_option, $value);
                delete_option($old_option);
            }
        }
    }

    private function migration_tables()
    {
        global $wpdb;

        $tables = [
            'integrate_dropbox_files'       => 'indbox_files',
            'integrate_dropbox_shortcodes'  => 'indbox_shortcodes',
            'integrate_dropbox_user_access' => 'indbox_user_access',
        ];

        foreach ($tables as $old_table => $new_table) {
            $old_table_name = "{$wpdb->prefix}$old_table";
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $table = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $old_table_name));
            if ($table === $old_table_name) {
                $new_table = "{$wpdb->prefix}$new_table";
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->query($wpdb->prepare("RENAME TABLE %i TO %i", $old_table_name, $new_table));
            }
        }
    }
}
