<?php

namespace CodeConfig\IDB\Integrations\Elementor;

defined('ABSPATH') || exit('No direct script access allowed');

class EmbedDocuments extends BaseWidget
{
    public function get_name()
    {
        return 'ccpidb-embed-documents';
    }
    public function get_title()
    {
        return __('Embed Documents', 'integrate-dropbox');
    }
    public function get_icon()
    {
        return 'ccpidb-embed-document ccpidb-icon-pro';
    }
    public function get_categories()
    {
        return ['integrate-dropbox', 'basic'];
    }

    protected function get_module_type()
    {
        return 'embed-documents';
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
