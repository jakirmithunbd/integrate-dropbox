<?php

namespace CodeConfig\IDB\API\Controllers;

use CodeConfig\IDB\API\BaseController;
use CodeConfig\IDB\Models\Files;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined('ABSPATH') || exit('No direct script access allowed');

class Photo extends BaseController
{
    public function __construct()
    {
        parent::__construct('integrate-dropbox/v1', 'photos');
    }

    public function register_routes(): void
    {
        register_rest_route($this->namespace, "{$this->rest_base}", [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'getAllPhoto'],
                'permission_callback' => [$this, 'manageFilePermission'],
            ]
        ]);
    }

    public function getAllPhoto(WP_REST_Request $request): WP_REST_Response
    {
        $perPage = $request->get_param('perPage') ? (int) $request->get_param('perPage') : 40;
        $page    = $request->get_param('page') ? (int) $request->get_param('page') : 1;
        $orderBy = $request->get_param('orderBy') ?: 'name';
        $order   = $request->get_param('order') ?: 'desc';

        $photos = Files::getInstance()->getAllPhotos(
            [
                'perPage' => $perPage,
                'page'    => $page,
                'orderBy' => $orderBy,
                'order'   => $order
            ]
        );


        if (is_wp_error($photos)) {
            return $this->errorResponse('Failed to get all photos', self::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->successResponse($photos, 'Photos retrieved successfully');
    }
}
