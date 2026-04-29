<?php

namespace CodeConfig\IDB\API;

use CodeConfig\IDB\API\Controllers\Account;
use CodeConfig\IDB\API\Controllers\File;
use CodeConfig\IDB\API\Controllers\Folder;
use CodeConfig\IDB\API\Controllers\MediaLibrary;
use CodeConfig\IDB\API\Controllers\Notices;
use CodeConfig\IDB\API\Controllers\Photo;
use CodeConfig\IDB\API\Controllers\Settings;
use CodeConfig\IDB\API\Controllers\Shortcode;
use CodeConfig\IDB\API\Controllers\Users;
use CodeConfig\IDB\Utils\Singleton;

defined('ABSPATH') || exit('No direct script access allowed');

class ApiRegistry
{
    use Singleton;
    private array $controllers = [];

    public function doHooks()
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
        $this->register_controllers();
    }

    private function register_controllers(): void
    {
        $this->controllers = [
            'account'       => new Account(),
            'settings'      => new Settings(),
            'shortcode'     => new Shortcode(),
            'notice'        => new Notices(),
            'users'         => new Users(),
            'file'          => new File(),
            'folder'        => new Folder(),
            'photo'         => new Photo(),
            'media-library' => new MediaLibrary(),
        ];
    }


    public function registerRoutes(): void
    {
        foreach ($this->controllers as $controller) {
            $controller->register_routes();
        }
    }

    public function get_controller(string $name): ?BaseController
    {
        return $this->controllers[$name] ?? null;
    }
}
