<?php

namespace CodeConfig\IDB\Integrations\Elementor;

defined('ABSPATH') || exit('No direct script access allowed');

class MediaPlayer extends BaseWidget
{
    public function get_name()
    {
        return 'ccpidb-media-player';
    }
    public function get_title()
    {
        return __('Media Player', 'integrate-dropbox');
    }
    public function get_icon()
    {
        return 'ccpidb-media-player ccpidb-icon-pro';
    }
    public function get_categories()
    {
        return ['integrate-dropbox', 'basic'];
    }

    protected function get_module_type()
    {
        return 'media-player';
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
