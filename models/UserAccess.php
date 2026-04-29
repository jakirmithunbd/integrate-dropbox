<?php

namespace CodeConfig\IDB\Models;

defined('ABSPATH') || exit('No direct script access allowed');

// phpcs:disable WordPress.DB.DirectDatabaseQuery

use CodeConfig\IDB\Utils\Helpers;
use CodeConfig\IDB\Utils\Singleton;

use function in_array;
use function is_array;

class UserAccess extends BaseModel
{
    use Singleton;
    private $table;
    /**
     * @var \wpdb
     * @access private
     */
    private $wpdb;

    private function __construct()
    {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = "{$wpdb->prefix}ccpidb_user_access";
    }

    // Create a new record
    public function create($type, $value, $folders = null, $pages = [])
    {
        global $wpdb;

        $isExists = $this->get_by($type, $value);

        if ($isExists) {
            $data = [
                'folders'    => maybe_serialize($folders),
                'pages'      => maybe_serialize($pages),
                'updatedAt'  => current_time('mysql', 1),
            ];

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update(
                $this->table,
                $data,
                ['id' => $isExists['id']],
                ['%s', '%s', '%s'],
                ['%d']
            );

            return $isExists['id'];
        }

        $data = [
            'type'       => $type,
            'value'      => $value,
            'folders'    => maybe_serialize($folders),
            'pages'      => maybe_serialize($pages),
        ];

        $format = ['%s', '%s', '%s', '%s'];
        $this->wpdb->insert($this->table, $data, $format);

        $id = $this->wpdb->insert_id;
        if (!empty($id)) {
            $this->syncMediaLibraryFolders($pages, $folders);
        }

        return $id;
    }

    public function get($id)
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE id = %d", $this->table, $id), ARRAY_A);

        if ($result) {
            if (isset($result['folders'])) {
                $result['folders'] = maybe_unserialize($result['folders']);
            }

            if (isset($result['pages'])) {
                $result['pages'] = maybe_unserialize($result['pages']);
            }
        }

        return $result;
    }

    public function get_by($type, $value)
    {
        global $wpdb;

        if (empty($type) || empty($value)) {
            return false;
        }

        $allowTypes  = ['user', 'role'];

        if (!in_array($type, $allowTypes, true)) {
            return false;
        }

        if (empty($value)) {
            return false;
        }

        $query  = $wpdb->prepare("SELECT * FROM %i WHERE type = %s", $this->table, $type);

        if (is_array($value)) {
            $placeholders = implode(',', array_fill(0, count($value), '%s'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            $query       .= $wpdb->prepare(" AND value IN ($placeholders)", $value);
        } else {
            $query       .= $wpdb->prepare(" AND value = %s", $value);
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->get_row($query, ARRAY_A);

        if (!empty($result)) {
            if (isset($result['folders'])) {
                $result['folders'] = maybe_unserialize($result['folders']);
            }

            if (isset($result['pages'])) {
                $result['pages'] = maybe_unserialize($result['pages']);
            }
        } else {
            $result = false;
        }

        return $result;
    }


    // Read folders by user name and role
    public function getAccessData($username, $roles)
    {
        $userData = $this->get_by('user', $username);

        if (!empty($userData)) {
            return $userData;
        }

        $roleData = $this->get_by('role', $roles);

        if (!empty($roleData)) {
            return $roleData;
        }

        return [];
    }


    public function getAll()
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i ORDER BY createdAt DESC", $this->table), ARRAY_A);

        foreach ($results as &$record) {
            $record['folders'] = maybe_unserialize($record['folders']);
            $record['pages']   = maybe_unserialize($record['pages']);
        }

        return $results;
    }


    // Update a record by ID
    public function update($id, $type, $value, $folders = [], $pages = [])
    {
        if (empty($id)) {
            return false;
        }

        $data = [
            'type'       => $type,
            'value'      => $value,
            'folders'    => maybe_serialize($folders),
            'pages'      => maybe_serialize($pages),
            'updatedAt'  => current_time('mysql', 1),
        ];
        $where = ['id' => $id];

        $format       = ['%s', '%s', '%s', '%s', '%s'];
        $where_format = ['%d'];

        $result  = $this->wpdb->update($this->table, $data, $where, $format, $where_format);

        $this->syncMediaLibraryFolders($pages, $folders);

        return $result;
    }

    // Delete a record by ID
    public function delete($id)
    {
        $where        = ['id' => $id];
        $where_format = ['%d'];

        return $this->wpdb->delete($this->table, $where, $where_format);
    }

    private function syncMediaLibraryFolders($pages, $folders)
    {
        if (!is_array($pages) || empty($pages) || is_array($pages) && !in_array('media_library', $pages, true)) {
            return;
        }

        $settingKey = 'integrations.mediaLibrary.folders';
        $MLFolders  = Helpers::getSetting('integrations.mediaLibrary.folders', []);

        if (is_array($MLFolders)) {
            $updatedFolders = array_values(array_unique(array_merge($MLFolders, $folders)));
            Helpers::updateSetting($settingKey, $updatedFolders);
        }
    }
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery
