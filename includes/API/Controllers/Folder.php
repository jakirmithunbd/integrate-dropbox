<?php

namespace CodeConfig\IDB\API\Controllers;

use CodeConfig\IDB\API\BaseController;
use CodeConfig\IDB\App\Accounts;
use CodeConfig\IDB\App\App;
use CodeConfig\IDB\Models\Shortcode;
use CodeConfig\IDB\Models\UserAccess;
use CodeConfig\IDB\Shortcode\Notifications;
use CodeConfig\IDB\Utils\Helpers;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined('ABSPATH') || exit('No direct script access allowed');

class Folder extends BaseController
{
    public function __construct()
    {
        parent::__construct('integrate-dropbox/v1', 'folder');
    }

    public function register_routes(): void
    {

        register_rest_route($this->namespace, "{$this->rest_base}/create", [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'create'],
                'permission_callback' => [$this, 'managePermission'],
                'args'                => [
                    'name'    => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                    'fileKey' => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                    'shortcodeId' => [
                        'type'     => 'integer',
                        'required' => false,
                    ],
                ],
            ]
        ]);

        register_rest_route($this->namespace, "{$this->rest_base}/tree", [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'tree'],
                'permission_callback' => [$this, 'managePermission'],
            ]
        ]);

        register_rest_route($this->namespace, "{$this->rest_base}/(?P<fileKey>[^/]+)", [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get'],
                'permission_callback' => [$this, 'getFolderPermission'],
                'args'                => [
                    'fileKey'      => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                    'from'    => [
                        'type'     => 'string',
                        'required' => false,
                        'default'  => 'cache',
                    ],
                    'perPage' => [
                        'type'     => 'integer',
                        'required' => false,
                        'default'  => 20,
                    ],
                    'page'    => [
                        'type'     => 'integer',
                        'required' => false,
                        'default'  => 1,
                    ],
                    'orderBy' => [
                        'type'     => 'string',
                        'required' => false,
                        'default'  => 'updatedAt',
                    ],
                    'order'   => [
                        'type'     => 'string',
                        'required' => false,
                        'default'  => 'desc',
                    ],
                    'types' => [
                        'type'     => 'string',
                        'required' => false,
                        'default'  => 'all',
                    ],
                    'search' => [
                        'type'     => 'string',
                        'required' => false,
                        'default'  => null,
                    ],
                    'searchScope' => [
                        'type'     => 'string',
                        'required' => false,
                        'default'  => 'folder',
                    ],
                ]
            ]
        ]);
    }

    public function get(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $key           = $request->get_param('fileKey');
            $from          = $request->get_param('from');
            $perPage       = $request->get_param('perPage');
            $page          = $request->get_param('page');
            $orderBy       = $request->get_param('orderBy');
            $order         = $request->get_param('order');
            $types         = $request->get_param('types');
            $search        = $request->get_param('search');
            // $searchScope   = $request->get_param('searchScope');

            $types = $types === 'all' ? [] : array_filter(array_map('trim', explode(',', $types)));

            $args = [
                'from'          => $from,
                'perPage'       => $perPage,
                'page'          => $page,
                'orderBy'       => $orderBy,
                'order'         => $order,
                'types'         => $types,
                'search'        => $search,
                // 'searchScope'   => $searchScope,
            ];

            $key = $key === 'root' ? '/' : $key;

            $folder = App::getInstance()->getFolder($key, $args);
            if (is_wp_error($folder)) {
                $message = $folder->get_error_message();
                if ($json = json_decode($message, true)) {
                    if (isset($json['error_summary'])) {
                        $message = $json['error_summary'];
                        if (strpos($message, 'path/not_found') !== false) {
                            $message = 'Folder not found or access denied. Please check that the folder exists and you have permission in Dropbox.';
                        }
                    }
                }

                return $this->errorResponse($message, self::HTTP_INTERNAL_SERVER_ERROR);
            }

            return $this->successResponse($folder, 'Folder retrieved successfully');

        } catch (\Exception $e) {
            return $this->handleException($e, 'Failed to retrieve folder');
        }
    }

    public function tree(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $folderKey   = $request->get_param('fileKey')       ?? "/";
            $shortcodeId = $request->get_param('shortcodeId')   ?? null;

            $folderTree = App::getInstance()->getFolderTree($folderKey, $shortcodeId);

            if (is_wp_error($folderTree)) {
                $message = $folderTree->get_error_message();
                if ($json = json_decode($message, true)) {
                    if (isset($json['error_summary'])) {
                        $message = $json['error_summary'];
                    }
                }

                return $this->errorResponse("Folder tree not found: $message", self::HTTP_INTERNAL_SERVER_ERROR);
            }

            return $this->successResponse($folderTree, 'Folder tree retrieved successfully');

        } catch (\Exception $e) {
            return $this->handleException($e, 'Failed to retrieve folder tree');
        }
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $name        = $request->get_param('name');
            $parent      = $request->get_param('fileKey');
            $shortcodeId = $request->get_param('shortcodeId') ?? null;

            if (empty($name) || empty($parent)) {
                return $this->errorResponse(__('Folder name and parent file key are required', 'integrate-dropbox'), self::HTTP_BAD_REQUEST);
            }

            $shortcode = null;

            if ($shortcodeId) {
                $shortcode = Shortcode::getInstance()->getShortcode($shortcodeId);

                if (is_wp_error($shortcode)) {
                    return $this->errorResponse($shortcode->get_error_message(), 500);
                }
            }

            if ($parent === '/') {
                $account     = Accounts::getInstance()->getAccount();
                $isTeam      = $account ? $account->isTeam() : false;

                if ($isTeam && !$shortcodeId) {
                    return $this->errorResponse('Create folder to the root directory is not allowed for team accounts.', self::HTTP_FORBIDDEN);
                }

                if ($shortcodeId) {
                    if (is_wp_error($shortcode)) {
                        return $this->errorResponse($shortcode->get_error_message(), 500);
                    }

                    $shortcodeType = $shortcode['type'] ?? '';

                    if ($shortcodeType === 'file-uploader') {
                        $files = $shortcode['data']['source']['fileKeys'] ?? [];

                        if (empty($files[0]['fileKey'])) {
                            return $this->errorResponse('No files found in the shortcode for root upload.', 400);
                        }

                        $parent = $files[0]['fileKey'];
                    }
                }
            }

            $folder = App::getInstance()->createFolder($name, $parent);
            if (is_wp_error($folder)) {
                return $this->errorResponse($folder->get_error_message(), self::HTTP_INTERNAL_SERVER_ERROR);
            }

            $folderData = $folder->getData();

            Notifications::notify(
                Notifications::NEW_FOLDER,
                $shortcodeId,
                $folderData['fileKey'],
            );

            if (($parent === '/' || $parent === '')) {
                if (!empty($shortcode)) {
                    $shortcodeType = $shortcode['type'] ?? '';

                    if (empty($shortcodeType)) {
                        return $this->errorResponse('Shortcode type is missing', 500);
                    }

                    $isRootUpload  = $shortcode['data']['advanced']['fileBrowser']['headerOptions']['root_upload'] ?? false;

                    if ($shortcodeType === 'file-browser' && $isRootUpload) {
                        $folderKey     = $folderData['fileKey'];
                        $result        = Shortcode::getInstance()->insertFile($shortcodeId, $folderKey);
                        if (is_wp_error($result)) {
                            return $this->errorResponse($result->get_error_message(), self::HTTP_INTERNAL_SERVER_ERROR);
                        }
                    }
                } elseif (empty($shortcodeId) && ccpidbHasUserAccessPage('file_browser') === true) {
                    $userAccess = ccpidbGetCurrentUserAccess();

                    if (!empty($userAccess['folders']) && is_array($userAccess['folders']) && !empty($userAccess['type']) && !empty($userAccess['id']) && !empty($userAccess['value'])) {

                        $userAccess['folders'][] = $folderData['fileKey'];
                        $type                    = $userAccess['type'];
                        $value                   = $userAccess['value'];
                        $id                      = $userAccess['id'];

                        UserAccess::getInstance()->update($id, $type, $value, $userAccess['folders'], $userAccess['pages']);

                    }
                }
            }

            return $this->successResponse($folderData, 'Folder created successfully');

        } catch (\Exception $e) {
            return $this->handleException($e, 'Failed to create folder');
        }
    }

    public function getFolderPermission(WP_REST_Request $request)
    {
        if (ccpidbHasUserAccessPage('file_browser') === true) {
            return true;
        }

        $user = wp_get_current_user();
        if (! $user instanceof \WP_User) {
            return false;
        }

        $userName    = $user->user_login;
        $userRoles   = $user->roles;

        $accessSettings = UserAccess::getInstance()->getAccessData($userName, $userRoles);

        if (empty($accessSettings) && ccpidbHasUserAccessPage('file_browser') !== true) {
            return new WP_Error(403, 'You do not have permission to access this folder', ['status' => 403]);
        }

        $fileKey              = $request->get_param('fileKey');
        $accessSettingsFolder = $accessSettings['folders'] ?? [];

        if (!empty($fileKey) && 'root' !== $fileKey && !Helpers::validateFileKey($fileKey, $accessSettingsFolder)) {
            return new WP_Error(403, 'You do not have permission to access this folder', ['status' => 403]);
        }

        return true;
    }
}
