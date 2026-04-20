<?php

namespace CodeConfig\IDB\API\Controllers;

use CodeConfig\IDB\API\BaseController;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined('ABSPATH') || exit('No direct script access allowed');

class Menus extends BaseController
{
    public function __construct()
    {
        parent::__construct('integrate-dropbox/v1', 'menus');
    }

    public function register_routes(): void
    {
        register_rest_route($this->namespace, $this->rest_base, [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'getMenus'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($this->namespace, "{$this->rest_base}/(?P<id>[\\d]+)", [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'getMenu'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route($this->namespace, "{$this->rest_base}/(?P<id>[\\d]+)/items", [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'getMenuItems'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);
    }

    public function getMenus(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $menus = wp_get_nav_menus();

            if (empty($menus)) {
                return $this->errorResponse('No menus found', self::HTTP_NOT_FOUND);
            }

            $formatted_menus = array_map([$this, 'formatMenuData'], $menus);

            return $this->successResponse($formatted_menus, 'Menus retrieved successfully');

        } catch (\Exception $e) {
            return $this->handleException($e, 'Failed to retrieve menus');
        }
    }

    public function getMenu(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $menu_id = $request->get_param('id');
            $menu    = wp_get_nav_menu_object($menu_id);

            if (!$menu) {
                return $this->errorResponse('Menu not found', self::HTTP_NOT_FOUND);
            }

            return $this->successResponse(
                $this->formatMenuData($menu),
                'Menu retrieved successfully'
            );

        } catch (\Exception $e) {
            return $this->handleException($e, 'Failed to retrieve menu');
        }
    }

    public function getMenuItems(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $menu_id = $request->get_param('id');
            $items   = wp_get_nav_menu_items($menu_id);

            if (empty($items)) {
                return $this->errorResponse('No menu items found', self::HTTP_NOT_FOUND);
            }

            $formatted_items = array_map([$this, 'formatMenuItemData'], $items);

            return $this->successResponse($formatted_items, 'Menu items retrieved successfully');

        } catch (\Exception $e) {
            return $this->handleException($e, 'Failed to retrieve menu items');
        }
    }

    private function formatMenuData($menu): array
    {
        return [
            'id'    => (int) $menu->term_id,
            'name'  => sanitize_text_field($menu->name),
            'slug'  => sanitize_title($menu->slug ?? ''),
            'count' => (int) $menu->count,
        ];
    }

    private function formatMenuItemData($item): array
    {
        return [
            'id'     => (int) $item->ID,
            'title'  => sanitize_text_field($item->title),
            'url'    => esc_url($item->url),
            'parent' => (int) $item->menu_item_parent,
            'order'  => (int) $item->menu_order,
        ];
    }
}
