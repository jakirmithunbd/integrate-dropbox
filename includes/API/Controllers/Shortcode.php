<?php

namespace CodeConfig\IDB\API\Controllers;

use CodeConfig\IDB\API\BaseController;
use CodeConfig\IDB\Models\Shortcode as ShortcodeModel;
use Exception;

use function in_array;
use function is_array;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

defined('ABSPATH') || exit('No direct script access allowed');

class Shortcode extends BaseController
{
    public function __construct()
    {
        parent::__construct("integrate-dropbox/v1", "shortcode");
    }

    /**
     * Extract HTTP status code from WP_Error
     *
     * @param WP_Error $error
     * @return int
     */
    private function getErrorStatusCode(WP_Error $error): int
    {
        $errorData = $error->get_error_data();

        return isset($errorData['status']) ? (int) $errorData['status'] : self::HTTP_INTERNAL_SERVER_ERROR;
    }

    public function register_routes(): void
    {
        register_rest_route($this->namespace, $this->rest_base, [
            [
                "methods"                 => WP_REST_Server::READABLE,
                "callback"                => [$this, "getAll"],
                'permission_callback'     => [$this, 'manageModuleBuilderPermission'],
            ],
            [
                "methods"             => WP_REST_Server::CREATABLE,
                "callback"            => [$this, "add"],
                'permission_callback' => [$this, 'manageModuleBuilderPermission'],
                "args"                => $this->get_create_params(),
            ],
            [
                "methods"             => WP_REST_Server::DELETABLE,
                "callback"            => [$this, "delete"],
                "permission_callback" => [$this, "manageModuleBuilderPermission"],
            ]
        ]);

        register_rest_route($this->namespace, "{$this->rest_base}/duplicate", [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'duplicate'],
                'permission_callback' => [$this, 'manageModuleBuilderPermission'],
            ]
        ]);

        register_rest_route($this->namespace, "{$this->rest_base}/import", [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'import'],
                'permission_callback' => [$this, 'manageModuleBuilderPermission'],
            ]
        ]);

        register_rest_route($this->namespace, "{$this->rest_base}/(?P<type>file-browser|file-uploader|gallery|slider-carousel|media-player|file-list|embed-documents|search-box)", [
            [
                'methods'             => WP_REST_Server::READABLE,
                'permission_callback' => [$this, 'manageModuleBuilderPermission'],
                'callback'            => [$this, 'getDefaultTemplate'],
                'args'                => [
                    'type' => [
                        'validate_callback' => fn ($param) => in_array($param, ['file-browser', 'file-uploader', 'gallery', 'slider-carousel', 'media-player', 'file-list', 'embed-documents', 'search-box'], true),
                    ],
                ],
            ]
        ]);

        register_rest_route($this->namespace, "{$this->rest_base}/(?P<shortcodeId>[^/]+)", [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get'],
                'permission_callback' => [$this, 'managePermission'],
                'args'                => [
                    'shortcodeId' => [
                        'required' => true,
                        'type'     => 'integer',
                    ],
                    'page' => [
                        'required' => false,
                        'type'     => 'integer',
                        'default'  => 1,
                    ],
                    'perPage' => [
                        'required' => false,
                        'type'     => 'integer',
                        'default'  => 20,
                    ],
                    'fileKey' => [
                        'required' => false,
                        'type'     => 'string',
                        'default'  => '/',
                    ],
                    'order' => [
                        'required' => false,
                        'type'     => 'string',
                        'default'  => 'DESC',
                    ],
                    'orderBy' => [
                        'required' => false,
                        'type'     => 'string',
                        'default'  => 'createdAt',
                    ],
                    'search' => [
                        'required' => false,
                        'type'     => 'string',
                        'default'  => '',
                    ],
                    'searchScope' => [
                        'required' => false,
                        'type'     => 'string',
                        'default'  => 'folder',
                    ],
                    'from' => [
                        'required' => false,
                        'type'     => 'string',
                        'default'  => 'cache',
                    ],
                    'password' => [
                        'required' => false,
                        'type'     => 'string',
                        'default'  => '',
                    ],
                    'types' => [
                        'required' => false,
                        'type'     => 'string',
                        'default'  => 'all',
                    ],
                    'isAdmin' => [
                        'required' => false,
                        'type'     => 'boolean',
                        'default'  => false,
                    ]
                ],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'update'],
                'permission_callback' => [$this, 'manageModuleBuilderPermission'],
            ]
        ]);
    }

    public function getDefaultTemplate(WP_REST_Request $request)
    {
        $type = $request->get_param('type');

        try {
            $template = ccpidbGetModuleDefaultData($type);

            if (empty($template) || is_wp_error($template)) {
                return $this->errorResponse(__('Default template not found.', 'integrate-dropbox'), self::HTTP_NOT_FOUND);
            }

            return $this->successResponse([
                'shortcode' => $template,
            ]);
        } catch (Exception $e) {
            return $this->handleException($e, __('Failed to retrieve default template.', 'integrate-dropbox'));
        }
    }

    public function add(WP_REST_Request $request)
    {
        $title       = $request->get_param('title');
        $type        = $request->get_param('type');
        $status      = $request->get_param('status');
        $data        = $request->get_param('data');
        $location    = $request->get_param('location');
        $integration = $request->get_param('integration');

        try {
            $shortcode = ShortcodeModel::getInstance()->add([
                'title'       => $title,
                'type'        => $type,
                'status'      => $status,
                'data'        => $data,
                'locations'   => $location,
                'integration' => $integration,
            ]);

            if (is_wp_error($shortcode)) {
                return $this->errorResponse($shortcode->get_error_message(), $shortcode->get_error_code());
            }

            return $this->successResponse([
                'shortcode' => $shortcode,
            ]);
        } catch (Exception $e) {
            return $this->handleException($e, __('Failed to create shortcode.', 'integrate-dropbox'));
        }
    }

    public function get(WP_REST_Request $request)
    {
        $id          = (int) $request->get_param('shortcodeId');
        $page        = $request->get_param('page');
        $perPage     = $request->get_param('perPage');
        $fileKey     = $request->get_param('fileKey');
        $order       = $request->get_param('order');
        $orderBy     = $request->get_param('orderBy');
        $search      = $request->get_param('search');
        $searchScope = $request->get_param('searchScope');
        $from        = $request->get_param('from');
        $password    = $request->get_param('password');
        $types       = $request->get_param('types');
        $isAdmin     = $request->get_param('isAdmin');

        $types = $types === 'all' ? [] : array_filter(array_map('trim', explode(',', $types)));

        try {
            $shortcode = ShortcodeModel::getInstance()->get($id, [
                'page'        => $page,
                'perPage'     => $perPage,
                'fileKey'     => $fileKey,
                'order'       => $order,
                'orderBy'     => $orderBy,
                'search'      => $search,
                'searchScope' => $searchScope,
                'from'        => $from,
                'password'    => $password,
                'types'       => $types,
                'isAdmin'     => $isAdmin,
            ]);

            if (is_wp_error($shortcode)) {
                return $this->errorResponse($shortcode->get_error_message(), self::HTTP_INTERNAL_SERVER_ERROR);
            }

            if (empty($shortcode)) {
                return $this->errorResponse(__('Shortcode not found.', 'integrate-dropbox'), self::HTTP_NOT_FOUND);
            }

            return $this->successResponse([
                'shortcode' => $shortcode,
            ]);
        } catch (Exception $e) {
            return $this->handleException($e, __('Failed to retrieve shortcode.', 'integrate-dropbox'));
        }
    }

    public function getAll(WP_REST_Request $request)
    {
        $config   = $request->get_query_params();

        $defaults = [
            'type'    => 'all',
            'search'  => '',
            'status'  => 'all',
            'order'   => 'DESC',
            'orderBy' => 'createdAt',
            'page'    => 1,
            'perPage' => 10,
        ];

        $queryArgs = wp_parse_args($config, $defaults);

        $queryArgs['page']    = (int) $queryArgs['page'];
        $queryArgs['perPage'] = (int) $queryArgs['perPage'];

        try {
            $shortcodes   = ShortcodeModel::getInstance()->getAll($queryArgs);
            $totalResult  = ShortcodeModel::getInstance()->countRecords($queryArgs);

            if (is_wp_error($shortcodes)) {
                return $this->errorResponse($shortcodes->get_error_message(), $this->getErrorStatusCode($shortcodes));
            }

            $total      = (int) $totalResult;
            $totalPages = $total > 0 ? (int) ceil($total / $queryArgs['perPage']) : 0;
            $hasMore    = $queryArgs['page'] < $totalPages;

            return $this->successResponse([
                'shortcodes' => $shortcodes,
                'totalPages' => $totalPages,
                'hasMore'    => $hasMore,
                'total'      => $total,
                'page'       => (int) $queryArgs['page'],
            ]);

        } catch (Exception $e) {
            return $this->handleException($e, __('Failed to retrieve shortcodes.', 'integrate-dropbox'));
        }
    }

    public function update(WP_REST_Request $request)
    {
        $id       = (int) $request->get_param('id');
        $title    = $request->get_param('title');
        $type     = $request->get_param('type');
        $status   = $request->get_param('status');
        $data     = $request->get_param('data');
        $location = $request->get_param('location');

        try {
            $shortcode = ShortcodeModel::getInstance()->add([
                'id'        => $id,
                'title'     => $title,
                'type'      => $type,
                'status'    => $status,
                'data'      => $data,
                'locations' => $location,
            ]);

            if (is_wp_error($shortcode)) {
                return $this->errorResponse($shortcode->get_error_message(), $shortcode->get_error_code());
            }

            return $this->successResponse([
                'shortcode' => $shortcode,
            ]);
        } catch (Exception $e) {
            return $this->handleException($e, __('Failed to update shortcode.', 'integrate-dropbox'));
        }
    }

    public function delete(WP_REST_Request $request)
    {
        $ids = $request->get_param('ids');

        try {
            $deleted = ShortcodeModel::getInstance()->remove($ids);

            if (is_wp_error($deleted)) {
                return $this->errorResponse($deleted->get_error_message(), $this->getErrorStatusCode($deleted));
            }

            return $this->successResponse([], __('Shortcode deleted successfully.', 'integrate-dropbox'));
        } catch (Exception $e) {
            return $this->handleException($e, __('Failed to delete shortcode.', 'integrate-dropbox'));
        }
    }

    public function duplicate(WP_REST_Request $request)
    {
        $ids = $request->get_param('ids');

        if (empty($ids)) {
            return $this->errorResponse(__('Shortcode IDs are required.', 'integrate-dropbox'), self::HTTP_BAD_REQUEST);
        }

        if (!is_array($ids)) {
            $ids = [$ids];
        }

        try {
            $result = ShortcodeModel::getInstance()->duplicate($ids);

            if (is_wp_error($result)) {
                $errorData  = $result->get_error_data();
                $statusCode = isset($errorData['status']) ? (int) $errorData['status'] : self::HTTP_INTERNAL_SERVER_ERROR;

                return $this->errorResponse($result->get_error_message(), $statusCode);
            }

            return $this->successResponse([
                'duplicated' => $result,
            ], __('Shortcode(s) duplicated successfully.', 'integrate-dropbox'));
        } catch (Exception $e) {
            return $this->handleException($e, __('Failed to duplicate shortcode.', 'integrate-dropbox'));
        }
    }

    public function import(WP_REST_Request $request)
    {
        $shortcodes = $request->get_param('shortcodes');

        if (empty($shortcodes)) {
            return $this->errorResponse(__('Import shortcodes is required.', 'integrate-dropbox'), self::HTTP_BAD_REQUEST);
        }

        try {
            $importedShortcodes = ShortcodeModel::getInstance()->import($shortcodes);

            if (is_wp_error($importedShortcodes)) {
                return $this->errorResponse($importedShortcodes->get_error_message(), $this->getErrorStatusCode($importedShortcodes));
            }

            return $this->successResponse([
                'imported' => $importedShortcodes,
            ], __('Shortcodes imported successfully.', 'integrate-dropbox'));
        } catch (\Exception $e) {
            return $this->handleException($e, __('Failed to import shortcodes.', 'integrate-dropbox'));
        }
    }

    private function get_create_params(): array
    {
        return [
            'title' => [
                'required'    => true,
                'type'        => 'string',
                'description' => 'Shortcode title',
            ],
            'type' => [
                'required'    => true,
                'type'        => 'string',
                'description' => 'Shortcode type',
            ],
            'status' => [
                'type'        => 'string',
                'description' => 'Shortcode status',
                'enum'        => ['active', 'inactive'],
                'default'     => 'active',
            ],
            'data' => [
                'required'          => true,
                'type'              => 'object',
                'description'       => 'Shortcode data',
                'validate_callback' => function ($value, $request, $param) {
                    if (!is_array($value)) {
                        return new \WP_Error('invalid_data', 'Data must be an array.');
                    }

                    return true;
                },
            ],
            'location' => [
                'type'        => 'string',
                'description' => 'Shortcode location',
            ],
        ];
    }
}
