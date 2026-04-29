<?php

namespace CodeConfig\IDB\Updates;

use CodeConfig\IDB\Utils\Singleton;
use Exception;
use WP_Error;

defined('ABSPATH') or exit('Hey, what are you doing here? You silly human!');

/**
 * Update class for version 1.3.10
 *
 * Handles database migrations and data format updates for version 1.3.7.
 * This includes table structure updates, option migrations, and data format changes.
 *
 * @package CodeConfig\IDB\Updates
 * @version 1.3.10
 * @since 1.3.10
 */
class Update_1_3_10
{
    use Singleton;

    /**
     * Constructor - Initialize all migrations
     *
     * @throws Exception If critical migrations fail
     */
    public function run_update()
    {

        try {
            if (version_compare(get_option('ccpidb_version', '0.0.0'), CCPIDB_VERSION, '>=')) {
                return;
            }

            $this->migrationFileListModules();

            return '1.3.10';
        } catch (Exception $th) {
            return new WP_Error(400, 'Update to version 1.3.10 failed: ' . $th->getMessage());
        }
    }


    private function migrationFileListModules()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'ccpidb_shortcodes';

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name) {
            $getFileListModules = $wpdb->get_results(
                $wpdb->prepare("SELECT id, data FROM %i WHERE type = %s", $table_name, 'file-list'),
                ARRAY_A
            );

            foreach ($getFileListModules as $module) {
                $data = maybe_unserialize($module['data']);
                if (is_array($data) && !empty($data)) {
                    $migratedData = $this->migrateFileListData($data);
                    $wpdb->update(
                        $table_name,
                        ['data' => maybe_serialize($migratedData)],
                        ['id'   => $module['id']]
                    );
                }
            }
        }
    }

    private function migrateFileListData($data)
    {
        if (isset($data['advanced']['fileList'])) {
            $oldFileList = $data['advanced']['fileList'];

            $data['advanced']['fileList'] = [
                'activeView'       => 'default',
                'folderExpandable' => false,
                'folderRecursive'  => true,
                'listDisplay'      => [
                    'name'      => ['enable' => true, 'text' => 'File Info'],
                    'thumbnail' => ['enable' => true],
                    'extension' => ['enable' => $oldFileList['showFileExtension'] ?? false, 'text' => 'Extension'],
                    'size'      => ['enable' => $oldFileList['showFileSize'] ?? true, 'text' => 'Size'],
                    'date'      => ['enable' => $oldFileList['showTimeStamp'] ?? true, 'text' => 'Modified'],
                    'actions'   => ['enable' => true, 'text' => 'Actions']
                ]
            ];
        }

        if (isset($data['permissions'])) {
            $data['permissions']['share'] = [
                'userAccess'       => 'everyone',
                'loggedInUserType' => 'users',
                'displayFor'       => [],
                'enable'           => false
            ];

            $data['permissions']['rename'] = [
                'userAccess'       => 'everyone',
                'loggedInUserType' => 'users',
                'displayFor'       => [],
                'enable'           => false
            ];

            $data['permissions']['delete'] = [
                'userAccess'          => 'everyone',
                'loggedInUserType'    => 'users',
                'displayFor'          => [],
                'enable'              => false,
                'isMigrateAttachment' => false
            ];

            if (!isset($data['permissions']['download'])) {
                $data['permissions']['download'] = [
                    'userAccess'       => 'everyone',
                    'loggedInUserType' => 'users',
                    'displayFor'       => [],
                    'enable'           => false
                ];
            }

            if (!isset($data['permissions']['preview'])) {
                $data['permissions']['preview'] = [
                    'userAccess'       => 'everyone',
                    'loggedInUserType' => 'users',
                    'displayFor'       => [],
                    'enable'           => false
                ];
            }
        }

        if (isset($data['notifications'])) {
            $data['notifications']['download']  = false;
            $data['notifications']['preview']   = false;
            $data['notifications']['share']     = false;
            $data['notifications']['rename']    = false;
            $data['notifications']['delete']    = false;
        }

        return $data;
    }
}
