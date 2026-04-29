<?php

namespace CodeConfig\IDB\Models;

defined('ABSPATH') || exit('No direct script access allowed');

// phpcs:disable WordPress.DB.DirectDatabaseQuery

class Attachment
{
    public static function get($folderPath)
    {
        if (!empty($folderPath)) {
            global $wpdb;

            $folder_meta_key   = '_ccpidb_media_folder_path';
            $file_meta_key     = '_ccpidb_media_file_key';

            $get_file_keys = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT meta_value AS file_key
                    FROM $wpdb->postmeta
                    WHERE meta_key = %s
                    AND post_id IN (
                        SELECT post_id
                        FROM $wpdb->postmeta
                        WHERE meta_key = %s
                        AND meta_value = %s
                    )",
                    $file_meta_key,
                    $folder_meta_key,
                    $folderPath
                ),
                ARRAY_A
            );

            $file_keys = wp_list_pluck($get_file_keys, 'file_key');

            return $file_keys;
        }

        return [];
    }

    public static function exists($fileKey)
    {
        if (!empty($fileKey)) {
            global $wpdb;

            $meta_key   = '_ccpidb_media_file_key';
            $meta_value = $fileKey;


            $post_exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT post_id
                    FROM $wpdb->postmeta
                    WHERE meta_key = %s
                    AND meta_value COLLATE utf8mb4_bin = %s
                    LIMIT 1",
                    $meta_key,
                    $meta_value
                )
            );

            if (!empty($post_exists)) {
                return (int) $post_exists;
            }
        }

        return false;
    }

    public static function clearAttachments()
    {
        $attachments = get_posts([
            'post_type'         => 'attachment',
            'post_status'       => 'inherit',
            'numberposts'       => -1,
            'meta_query'        => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                [
                    'key'     => '_ccpidb_media_file_key',
                    'compare' => 'EXISTS',
                ]
            ]
        ]);

        foreach ($attachments as $attachment) {
            wp_delete_attachment($attachment->ID, true);
        }
    }
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery
