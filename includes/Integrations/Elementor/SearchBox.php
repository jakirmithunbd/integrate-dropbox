<?php

namespace CodeConfig\IDB\Integrations\Elementor;

defined('ABSPATH') || exit('No direct script access allowed');

class SearchBox extends BaseWidget
{
    public function get_name()
    {
        return 'ccpidb-search-box';
    }
    public function get_title()
    {
        return __('Search Box', 'integrate-dropbox');
    }
    public function get_icon()
    {
        return 'ccpidb-search-box ccpidb-icon-pro';
    }
    public function get_categories()
    {
        return ['integrate-dropbox', 'basic'];
    }

    protected function get_module_type()
    {
        return 'search-box';
    }

    // public function get_custom_help_url(): string
    // {
    //     return '';
    // }

    protected function is_pro(): bool
    {
        return true;
    }
}
