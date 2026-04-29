<?php

namespace CodeConfig\IDB;

use CodeConfig\IDB\App\API\Files;
use CodeConfig\IDB\Models\Notices;
use CodeConfig\IDB\Utils\Singleton;
use Exception;

defined('ABSPATH') || exit('No direct script access allowed');

class Schedule
{
    use Singleton;

    private function doHooks()
    {
        add_action('ccpidb_sync_existing_dropbox_files_event', [$this, 'handle_sync_existing_dropbox_files']);
        add_action('ccpidb_sync_media_library_event', [$this, 'handle_sync_media_library']);
    }

    /**
     * Handle syncing media library
     *
     * @return void
     */
    public function handle_sync_media_library(): void
    {
        try {
            Integrations\MediaLibrary__premium_only::getInstance()->sync();
        } catch (Exception $th) {
            Notices::getInstance()->add(
                [
                    'type'    => 'error',
                    'message' => sprintf(
                        /* translators: %s: Error message */
                        __('Media Library sync failed: %s', 'integrate-dropbox'),
                        $th->getMessage()
                    ),
                ]
            );
        }
    }

    /**
     * Handle syncing existing Dropbox files
     *
     * @return void
     */
    public function handle_sync_existing_dropbox_files(): void
    {
        global $wpdb;

        $table     = "{$wpdb->prefix}ccpidb_files";
        $pageSize  = 100;
        $lastId    = 0;
        $apiCache  = [];

        while (true) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "
                SELECT id, path, accountId
                FROM %i
                WHERE path IS NOT NULL
                    AND id > %d
                ORDER BY id ASC
                LIMIT %d
                ",
                    $table,
                    $lastId,
                    $pageSize
                )
            );

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int) $row->id;

                if (empty($row->path) || empty($row->accountId)) {
                    continue;
                }

                // Reuse Files instance per account
                if (!isset($apiCache[$row->accountId])) {
                    $apiCache[$row->accountId] = new Files($row->accountId);
                }

                try {
                    $apiCache[$row->accountId]->getFile($row->path);
                } catch (\Throwable $e) {
                    Notices::getInstance()->add(
                        [
                            'type'    => 'error',
                            'message' => sprintf(
                                /* translators: 1: File ID, 2: File Path, 3: Error message */
                                __('Failed to sync Dropbox file (ID: %1$d, Path: %2$s): %3$s', 'integrate-dropbox'),
                                $row->id,
                                $row->path,
                                $e->getMessage()
                            ),
                        ]
                    );
                }
            }

            unset($rows);
        }
    }
}
