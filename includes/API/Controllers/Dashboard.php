<?php

namespace CodeConfig\IDB\API\Controllers;

use CodeConfig\IDB\API\BaseController;
use CodeConfig\IDB\Cache;
use CodeConfig\IDB\Models\Files;
use Exception;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined('ABSPATH') || exit('No direct script access allowed');

class Dashboard extends BaseController
{
    private const ALL_FIELDS = ['imageCache', 'sharedFiles', 'downloadedFiles', 'cachedFiles'];

    private $fs;

    public function __construct()
    {
        parent::__construct('integrate-dropbox/v1', 'dashboard');

        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        WP_Filesystem();
        global $wp_filesystem;
        $this->fs = $wp_filesystem;
    }

    public function register_routes(): void
    {
        // Single file cache delete
        register_rest_route($this->namespace, "{$this->rest_base}/cache/file", [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [$this, 'deleteCacheFile'],
            'permission_callback' => [$this, 'manageSettingsPermission'],
            'args'                => [
                'fileKey' => [
                    'required'    => true,
                    'type'        => 'string',
                    'description' => __('File key to delete from cache', 'integrate-dropbox'),
                ],
                'size' => [
                    'required'    => false,
                    'enum'        => ['md', 'lg', 'xl', '4xl', '5xl'],
                    'description' => __('Cache size to delete (all sizes if not specified)', 'integrate-dropbox'),
                ],
                'ext' => [
                    'required'    => false,
                    'default'     => 'webp',
                    'description' => __('File extension', 'integrate-dropbox'),
                ],
            ],
        ]);

        // Group cache delete (by size or all)
        register_rest_route($this->namespace, "{$this->rest_base}/cache", [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [$this, 'deleteCache'],
            'permission_callback' => [$this, 'manageSettingsPermission'],
            'args'                => [
                'type' => [
                    'required'    => false,
                    'enum'        => ['total', 'md', 'lg', 'xl', '4xl', '5xl'],
                    'description' => __('Type of cache to delete (total, or specific size)', 'integrate-dropbox'),
                    'default'     => 'total',
                ],
            ],
        ]);

        // Dashboard data endpoint
        register_rest_route($this->namespace, "{$this->rest_base}/", [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get'],
            'permission_callback' => [$this, 'manageSettingsPermission'],
            'args'                => [
                'fields' => [
                    'required'    => false,
                    'description' => __('Specific fields to retrieve (e.g., imageCache, sharedFiles).', 'integrate-dropbox'),
                ],
            ],
        ]);
    }

    public function deleteCache(WP_REST_Request $request): WP_REST_Response
    {
        $cacheType = $request->get_param('type') ?? 'total';

        try {
            $cache = new Cache();

            if ($cacheType === 'total') {
                $cache->clearCache();
                // Remove all cachedData from DB
                Files::getInstance()->deleteCachedData();
            } else {
                $cache->clearCache($cacheType);
                // Remove cachedData for this size from DB
                Files::getInstance()->deleteCachedData(null, $cacheType);
            }

            // Clear cached image cache data
            delete_transient('idb_dashboard_image_cache');

            $response = $this->getDashboardData(['imageCache' => true, 'cachedFiles' => true]);

            return $this->successResponse(
                $response,
                __('Cache deleted successfully.', 'integrate-dropbox')
            );
        } catch (Exception $e) {
            return $this->errorResponse(
                $e->getMessage(),
                __('Failed to delete cache.', 'integrate-dropbox')
            );
        }
    }

    /**
     * Delete a single cached file
     */
    public function deleteCacheFile(WP_REST_Request $request): WP_REST_Response
    {
        $fileKey = $request->get_param('fileKey');
        $size    = $request->get_param('size');
        $ext     = $request->get_param('ext') ?? 'webp';

        try {
            $cache = new Cache();

            if ($size) {
                // Delete specific size for this file
                $cache->deleteFile($fileKey, $size, $ext);
                Files::getInstance()->deleteCachedData($fileKey, $size);
            } else {
                // Delete all sizes for this file
                foreach (['md', 'lg', 'xl', '4xl', '5xl'] as $sizeFolder) {
                    $cache->deleteFile($fileKey, $sizeFolder, $ext);
                }
                Files::getInstance()->deleteCachedData($fileKey);
            }

            // Clear cached image cache data
            delete_transient('idb_dashboard_image_cache');

            $response = $this->getDashboardData(['imageCache' => true, 'cachedFiles' => true]);

            return $this->successResponse(
                $response,
                __('File cache deleted successfully.', 'integrate-dropbox')
            );
        } catch (Exception $e) {
            return $this->errorResponse(
                $e->getMessage(),
                __('Failed to delete file cache.', 'integrate-dropbox')
            );
        }
    }

    public function get(WP_REST_Request $request): WP_REST_Response
    {
        $fields = $request->get_param('fields');

        $requested      = $fields ? array_map('trim', explode(',', $fields)) : [];
        $expectedFields = $requested ? array_intersect($requested, self::ALL_FIELDS) : self::ALL_FIELDS;
        $expectedSet    = array_flip($expectedFields);

        try {
            $response = $this->getDashboardData($expectedSet);

            return $this->successResponse(
                $response,
                __('Dashboard data retrieved successfully.', 'integrate-dropbox')
            );

        } catch (Exception $e) {
            return $this->errorResponse(
                $e->getMessage(),
                __('Failed to retrieve dashboard data.', 'integrate-dropbox')
            );
        }
    }

    private function getDashboardData(array $expectedSet): array
    {
        $response = [];

        if (isset($expectedSet['imageCache'])) {
            $cacheKey   = 'idb_dashboard_image_cache';
            $imageCache = get_transient($cacheKey);

            if ($imageCache === false) {
                $imageCache = (new Cache())->calculateCacheSizeAndCount();
                set_transient($cacheKey, $imageCache, 10 * MINUTE_IN_SECONDS);
            }

            $response['imageCache'] = $imageCache;
        }

        $filesInstance = null;
        $needsFiles    = isset($expectedSet['sharedFiles']) || isset($expectedSet['downloadedFiles']) || isset($expectedSet['cachedFiles']);

        if ($needsFiles) {
            $filesInstance = Files::getInstance();
        }

        if (isset($expectedSet['sharedFiles'])) {
            $response['sharedFiles'] = $filesInstance->sharedFiles();
        }

        if (isset($expectedSet['downloadedFiles'])) {
            $response['downloadedFiles'] = $filesInstance->downloadedFiles();
        }

        if (isset($expectedSet['cachedFiles'])) {
            $response['cachedFiles'] = $filesInstance->cachedFiles();
        }

        return $response;
    }
}
