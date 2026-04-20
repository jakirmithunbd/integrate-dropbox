<?php

namespace CodeConfig\IDB\Integrations\Elementor;

use Elementor\Controls_Manager;

defined('ABSPATH') || exit('No direct script access allowed');

class Shortcode extends BaseWidget
{
    public function get_name()
    {
        return 'ccpidb-shortcode';
    }
    public function get_title()
    {
        return __('Shortcode List', 'integrate-dropbox');
    }
    public function get_icon()
    {
        return 'ccpidb-shortcode ccpidb-icon-pro';
    }
    public function get_categories()
    {
        return ['integrate-dropbox', 'basic'];
    }

    public function register_controls()
    {
        $this->start_controls_section('section_content', [
            'label' => __('Content', 'integrate-dropbox'),
        ]);

        $shortcode_options = $this->get_shortcode_options();

        $this->add_control('select_shortcode', [
            'label'       => __('Select Shortcode', 'integrate-dropbox'),
            'type'        => Controls_Manager::SELECT,
            'options'     => $shortcode_options,
            'default'     => '',
            'description' => __('Select a shortcode to display its content.', 'integrate-dropbox'),
            'label_block' => true,
        ]);

        $this->add_control('module_data', [
            'label'   => __('Module Data', 'integrate-dropbox'),
            'type'    => Controls_Manager::HIDDEN,
            'default' => $this->get_default_module_data(),
        ]);

        if (empty($shortcode_options)) {
            $this->add_control('no_shortcodes_notice', [
                'type' => Controls_Manager::RAW_HTML,
                'raw'  => '<div class="elementor-panel-alert elementor-panel-alert-warning">' .  __('No shortcodes found. Please create shortcodes first in the module builder.', 'integrate-dropbox') . '</div>',
            ]);
        }

        $this->end_controls_section();
    }

    protected function get_module_type()
    {
        return 'shortcode';
    }

    // public function get_custom_help_url(): string
    // {
    //     return '';
    // }

    protected function is_pro(): bool
    {
        return false;
    }

    public function render()
    {
        $settings           = $this->get_settings_for_display();
        $selected_shortcode = $settings['select_shortcode'] ?? '';

        if (empty($selected_shortcode) && is_user_logged_in()) {
            $this->render_shortcode_empty_notice();

            return;
        }

        if (!empty($selected_shortcode)) {
            $this->render_shortcode($selected_shortcode);
        }
    }

    protected function render_shortcode_empty_notice()
    {
        $args = [
            'title'       => __('Select a Shortcode', 'integrate-dropbox'),
            'description' => __('Please select a shortcode from the dropdown above to display its content.', 'integrate-dropbox'),
            'icon'        => 'code',
            'card_status' => 'warning',
        ];

        ccpidbGetTemplate('notice-card/notice-card-common', $args);
    }
}
