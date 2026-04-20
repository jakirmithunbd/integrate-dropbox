<?php

namespace CodeConfig\IDB\Updates;

use CodeConfig\IDB\Utils\Singleton;
use Exception;
use WP_Error;

defined('ABSPATH') or exit('Hey, what are you doing here? You silly human!');


class Update_1_2_16
{
    use Singleton;

    public function run_update()
    {
        try {
            $this->clean_deprecated_preview_url();
            $this->add_default_option();
            return '1.2.16';
        } catch (Exception $th) {
            return new WP_Error('update_failed', 'Update to version 1.2.16 failed: ' . $th->getMessage());
        }
    }

    private function clean_deprecated_preview_url()
    {
        global $wpdb;

        $tableName = $wpdb->prefix . 'integrate_dropbox_files';

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i 
            WHERE (`preview` IS NOT NULL AND `preview` != '') 
            OR (`download` IS NOT NULL AND `download` != '')",
                $tableName
            )
        );

        if (!empty($results)) {
            foreach ($results as $result) {
                $wpdb->update($tableName, ['preview' => '', 'download' => ''], ['file_id' => $result->file_id]);
            }
        }
    }

    public function add_default_option()
    {
        if (!get_option('indbox_encryption_key')) {
            update_option('indbox_encryption_key', wp_generate_uuid4());
        }
    }
}
