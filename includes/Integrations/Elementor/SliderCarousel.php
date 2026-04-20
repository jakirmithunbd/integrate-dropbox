<?php

namespace CodeConfig\IDB\Integrations\Elementor;

defined('ABSPATH') || exit('No direct script access allowed');

class SliderCarousel extends BaseWidget
{
    public function get_name()
    {
        return 'ccpidb-slider-carousel';
    }
    public function get_title()
    {
        return __('Slider Carousel', 'integrate-dropbox');
    }
    public function get_icon()
    {
        return 'ccpidb-slider-carousel';
    }
    public function get_categories()
    {
        return ['integrate-dropbox', 'basic'];
    }

    protected function get_module_type()
    {
        return 'slider-carousel';
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
