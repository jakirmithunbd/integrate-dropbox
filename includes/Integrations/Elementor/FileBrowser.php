<?php

namespace CodeConfig\IDB\Integrations\Elementor;

defined('ABSPATH') || exit('No direct script access allowed');

class FileBrowser extends BaseWidget
{
    public function get_name()
    {
        return 'ccpidb-file-browser';
    }
    public function get_title()
    {
        return __('File Browser', 'integrate-dropbox');
    }
    public function get_icon()
    {
        return 'ccpidb-file-browser';
    }
    public function get_categories()
    {
        return ['integrate-dropbox', 'basic'];
    }

    protected function get_module_type()
    {
        return 'file-browser';
    }

    protected function is_pro(): bool
    {
        return true;
    }
}
