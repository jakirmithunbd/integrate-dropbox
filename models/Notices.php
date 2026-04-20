<?php

namespace CodeConfig\IDB\Models;

use CodeConfig\IDB\Utils\Singleton;
use WP_Error;

defined('ABSPATH') || exit('No direct script access allowed');

/**
 * Notice Model Class for Integrate Dropbox Plugin
 *
 * This class extends BaseModel to provide notice/log management functionality
 * with full compatibility with the base model's CRUD operations and error handling.
 *
 * @package CodeConfig\IDB\Models
 * @since 1.0.0
 */
class Notices extends BaseModel
{
    use Singleton;

    /**
     * Allowed columns for ordering
     * @var array
     */
    private $allowedOrderColumns = ['id', 'createdAt', 'updatedAt', 'type', 'status', 'title'];

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct('ccpidb_logs');
    }

    /**
     * Get a single notice by ID
     *
     * @param int $id The notice ID
     * @param string $output Output type: OBJECT, ARRAY_A, or ARRAY_N
     * @return object|array|null|WP_Error
     */
    public function get(int $id, $output = OBJECT)
    {
        return $this->findSingleRecord("SELECT * FROM {$this->tableName} WHERE id = %d", [$id], $output);
    }

    /**
     * Get all notices
     *
     * @param array $args Optional arguments for pagination and filtering
     * @param string $output Output type: OBJECT, ARRAY_A, or ARRAY_N
     * @return array|WP_Error
     */
    public function getAll(array $args = [], $output = ARRAY_A)
    {

        $page    = intval($args['page'])    ?? 1;
        $perPage = intval($args['perPage']);
        $offset  = ($page - 1) * $perPage;

        $status  = $args['status'] ?? 'all';

        $sql     = "SELECT * FROM {$this->tableName} WHERE 1=1";
        $prompts = [];

        if (!empty($status) && $status !== 'all') {
            $sql .= " AND status = %s";
            $prompts[] = $status;
        }

        $sql .= " ORDER BY createdAt DESC LIMIT %d OFFSET %d";
        $prompts[] = $perPage;
        $prompts[] = $offset;

        $notices = $this->findMultipleRecords($sql, $prompts, $output);

        $count       = $this->countRecords();
        $unreadCount = $this->countRecords("status = %s", ['unread']);
        $hasMore     = $page < ceil($count / $perPage);

        $response = [
            'notices'     => array_values($notices),
            'hasMore'     => $hasMore,
            'total'       => $count,
            'currentPage' => $page
        ];

        if (!empty($unreadCount)) {
            $response['unreadCount'] = $unreadCount;
        }

        if ($hasMore) {
            $response['nextPage'] = $page + 1;
        }

        return $response;
    }


    /**
     * Add a new notice to the database.
     *
     * @param array $data {
     *                    An array of data to be inserted.
     *
     * @type string $moduleId    Module ID.
     * @type int $userId      User ID.
     * @type string $fileKey     File key.
     * @type string $fileName    File name.
     * @type string $page        Page.
     * @type array $data        Data.
     * @type string $type        Notice type.
     * @type string $title       Notice title.
     * @type string $status      Notice status. Default 'unread'.
     * @type string $description Notice description.
     *              }
     *
     * @return int|WP_Error The ID of the new notice on success, WP_Error on failure.
     */
    public function add(array $data)
    {
        if (empty($data['type']) || empty($data['title'])) {
            return new WP_Error(400, 'Type and title are required fields.');
        }

        $notice = [
            'moduleId'    => $data['moduleId'] ?? null,
            'userId'      => $data['userId']   ?? get_current_user_id(),
            'fileKey'     => $data['fileKey']  ?? null,
            'fileName'    => $data['fileName'] ?? null,
            'page'        => $data['page']     ?? null,
            'data'        => maybe_serialize($data['data'] ?? ''),
            'type'        => $data['type'],
            'title'       => $data['title'],
            'status'      => $data['status']      ?? 'unread',
            'description' => $data['description'] ?? null,
            'createdAt'   => current_time('mysql'),
            'updatedAt'   => current_time('mysql'),
        ];

        $format = [
            '%s', // moduleId
            '%d', // userId
            '%s', // fileKey
            '%s', // fileName
            '%s', // page
            '%s', // data
            '%s', // type
            '%s', // title
            '%s', // status
            '%s', // description
            '%s', // createdAt
            '%s', // updatedAt
        ];

        return $this->createRecord($notice, $format, ARRAY_A);
    }

    /**
     * Delete a single notice
     *
     * @param int $id The notice ID
     * @return bool|WP_Error
     */
    public function deleteNotice(int $id)
    {
        global $wpdb;
        if (empty($id)) {
            return new WP_Error(400, __('Invalid ID provided.', 'integrate-dropbox'));
        }

        $exitingRecord = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM %i WHERE `id` = %d LIMIT 1", $this->tableName, $id));

        if (empty($exitingRecord) || is_wp_error($exitingRecord)) {
            return new WP_Error(404, __('Notice not found.', 'integrate-dropbox'));
        }

        return $wpdb->delete($this->tableName, ['id' => $id], ['%d']) !== false;
    }

    /**
     * Delete all notices
     *
     * @return bool|WP_Error
     */
    public function deleteAll()
    {
        return $this->deleteRecords([], [], true);
    }

    /**
     * Change the status of a notice
     *
     * @param int $id The notice ID
     * @param string $status The new status
     * @param string $output Output type: 'bool', ARRAY_A, ARRAY_N, or OBJECT
     * @return bool|array|object|WP_Error
     */
    public function changeStatus(int $id, $output = ARRAY_A)
    {
        if (empty($id)) {
            return new WP_Error(400, __('Invalid ID provided.', 'integrate-dropbox'));
        }

        return $this->updateRecords(['status' => 'read'], ['id' => $id], ['%s'], ['%d'], ARRAY_A);
    }

    /**
     * Mark all notices as read
     *
     * @return bool|WP_Error
     */
    public function markAllAsRead()
    {
        return $this->updateRecords(['status' => 'read'], ['status' => 'unread'], ['%s'], ['%s']);
    }
}
