<?php

namespace CodeConfig\IDB;

defined('ABSPATH') || exit('No direct script access allowed');

use CodeConfig\IDB\Pages\AdminPages;
use CodeConfig\IDB\Utils\Singleton;

class Admin
{
    use Singleton;

    private function doHooks()
    {
        add_action('admin_menu', [AdminPages::class, 'adminMenu']);
        add_action('in_plugin_update_message-' . CCPIDB_PLUGIN_BASE, [$this, 'version_update_warning']);

    }

    public function version_update_warning($plugin_data)
    {
        $current_version = CCPIDB_VERSION;
        $new_version     = $plugin_data['new_version'];

        $current_version_minor_part = explode('.', $current_version)[1];
        $new_version_minor_part     = explode('.', $new_version)[1];

        if ($current_version_minor_part === $new_version_minor_part) {
            return;
        }

        ?>
		<hr class="ccpidb-major-update-warning__separator" />
        <style>
            .ccpidb-major-update-warning {
                border: 1px solid #d63638;
                background-color: #fff1f0;
                padding: 15px;
                margin-top: 15px;
                border-radius: 5px;
            }

            .ccpidb-major-update-warning__title {
                font-size: 16px;
                margin-bottom: 10px;
                display: flex;
                align-items: center;
                gap: 8px;
                color: #d63638;
            }

            .ccpidb-major-update-warning__message {
                font-size: 14px;
                line-height: 1.5;
            }

            .ccpidb-major-update-warning__separator {
                border: none;
                border-top: 1px solid #d63638;
                margin: 15px 0;
            }
            .ccpidb-major-update-warning + p {
                opacity: 0;
                height: 0;
            }
        </style>
        <div class="ccpidb-major-update-warning">
            <div>
                <div class="ccpidb-major-update-warning__title">
                    <span class="dashicons dashicons-info-outline"></span>
                    <strong><?php echo esc_html__('Important Update Notice', 'integrate-dropbox'); ?></strong>
                </div>
                <div class="ccpidb-major-update-warning__message">
                    <?php
                    printf(
                        /* translators: %1$s Link open tag, %2$s Link close tag */
                        esc_html__(
                            'This version introduces major improvements and internal changes. To ensure a smooth upgrade, we strongly recommend %1$screating a full backup of your site%2$s and testing the update in a staging environment before applying it to your live site.',
                            'integrate-dropbox'
                        ),
                        '<a href="https://codeconfig.dev/8-best-wordpress-backup-plugins-for-your-website/" target="_blank" rel="noopener noreferrer">',
                        '</a>'
                    ); ?>
                </div>
            </div>
        </div>
        <?php
    }
}
