<?php

namespace CodeConfig\IDB\Integrations;

use CodeConfig\IDB\Enqueue;
use CodeConfig\IDB\Integrations\Elementor\EmbedDocuments;
use CodeConfig\IDB\Integrations\Elementor\FileBrowser;
use CodeConfig\IDB\Integrations\Elementor\FileList;
use CodeConfig\IDB\Integrations\Elementor\Gallery;
use CodeConfig\IDB\Integrations\Elementor\MediaPlayer;
use CodeConfig\IDB\Integrations\Elementor\SearchBox;
use CodeConfig\IDB\Integrations\Elementor\Shortcode;
use CodeConfig\IDB\Integrations\Elementor\SliderCarousel;
use CodeConfig\IDB\Utils\Singleton;

defined('ABSPATH') or exit;

class Elementor extends BaseIntegration
{
    use Singleton;

    public function __construct()
    {
        parent::__construct('elementor', 'Elementor Blocks');
    }

    public function init(string $id, array $integration): void
    {
        add_action('elementor/editor/wp_head', [$this,'renderStyles']);
        add_action('plugin_loaded', function () {
            if (!defined('ELEMENTOR_VERSION')) {
                return;
            }
            add_action('elementor/elements/categories_registered', [$this, 'addCategory']);
            $hook = version_compare(\ELEMENTOR_VERSION, '3.5.0', '>=') ? 'elementor/widgets/register' : 'elementor/widgets/widgets_registered';
            add_action($hook, [$this, 'registerWidgets']);
        });
    }

    public function renderStyles()
    {
        Enqueue::getInstance()->add('common', 'css');
    }

    public function addCategory($elements_manager): void
    {
        $elements_manager->add_category('integrate-dropbox', [
            'title'=> __('Integrate Dropbox', 'integrate-dropbox'),
            'icon' => 'fa fa-cloud',
        ]);
    }

    public function registerWidgets($widgets_manager): void
    {
        $widgets = [
            FileBrowser::class,
            Gallery::class,
            FileList::class,
            MediaPlayer::class,
            SliderCarousel::class,
            SearchBox::class,
            EmbedDocuments::class,
            Shortcode::class,
        ];

        foreach ($widgets as $class) {
            if (class_exists($class)) {
                if (method_exists($widgets_manager, 'register')) {
                    $widgets_manager->register(new $class());
                } else {
                    $widgets_manager->register_widget_type(new $class());
                }
            }
        }
    }
}
