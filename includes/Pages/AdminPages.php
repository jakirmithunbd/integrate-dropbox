<?php

namespace CodeConfig\IDB\Pages;

defined('ABSPATH') || exit('No direct script access allowed');

class AdminPages
{
    private const SUB_MENU_PAGES = [
        [
            'id'       => 'file_browser',
            'menu'     => 'File Manager',
            'slug'     => CCPIDB_SLUG . '#/file-browser/all-files',
        ],
        [
            'id'       => 'module_builder',
            'menu'     => 'Module Builder',
            'slug'     => CCPIDB_SLUG . '#/module-builder',
        ],
        [
            'id'       => 'settings',
            'menu'     => 'Settings',
            'slug'     => CCPIDB_SLUG . '#/settings/accounts',
        ],
        [
            'id'       => 'dashboard',
            'menu'     => 'Dashboard',
            'slug'     => CCPIDB_SLUG . '#/dashboard/overview',
        ]
    ];

    /**
     * Adds the top level menu item for the Integration Dropbox settings page.
     *
     * @since 1.0.0
     */
    public static function adminMenu()
    {
        $isMenuAdded = false;
        foreach (self::SUB_MENU_PAGES as $page) {
            if (empty($page['id']) || empty($page['menu']) || empty($page['slug'])) {
                continue;
            }

            $page_id = $page['id'];
            if (!ccpidbHasUserAccessPage($page_id)) {
                continue;
            }

            if (!$isMenuAdded) {
                self::addMenuPage($page['menu'], $page['slug']);
                $isMenuAdded = true;
            } else {
                self::addSubMenuPage($page['menu'], $page['slug']);
            }
        }
    }

    public static function adminPage()
    {
        wp_enqueue_style('ccpidb-admin');
        wp_enqueue_script('ccpidb-file-browser');
        echo '<div id="ccpidb-admin" class="ccpidb-admin ccpidb-top-level-wrapper"></div>';
    }

    private static function addMenuPage($menu, $slug)
    {
        add_menu_page(
            'Integration Dropbox',
            'Dropbox',
            'read',
            CCPIDB_SLUG,
            [self::class, 'adminPage'],
            CCPIDB_ASSETS . '/images/dropbox.png',
            10
        );

        self::addSubMenuPage($menu, $slug);
        remove_submenu_page(CCPIDB_SLUG, CCPIDB_SLUG);
    }

    private static function addSubMenuPage($menu, $slug)
    {
        add_submenu_page(
            CCPIDB_SLUG,
            "$menu - Integration Dropbox",
            $menu,
            'read',
            $slug,
            '__return_null'
        );
    }
}
