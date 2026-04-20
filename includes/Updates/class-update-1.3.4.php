<?php

namespace CodeConfig\IDB\Updates;

use CodeConfig\IDB\Utils\Singleton;
use Exception;
use WP_Error;

defined('ABSPATH') or exit('Hey, what are you doing here? You silly human!');

/**
 * Update class for version 1.3.4
 *
 * Handles database migrations and data format updates for version 1.3.4.
 * This includes table structure updates, option migrations, and data format changes.
 *
 * @package CodeConfig\IDB\Updates
 * @version 1.3.4
 * @since 1.3.4
 */
class Update_1_3_4
{
    use Singleton;

    /**
     * Constructor - Initialize all migrations
     *
     * @throws Exception If critical migrations fail
     */
    public function run_update()
    {

        try {
            if (version_compare(get_option('ccpidb_version', '0.0.0'), CCPIDB_VERSION, '>=')) {
                return;
            }

            add_action('admin_init', [$this, 'init']);

            return '1.3.4';
        } catch (Exception $th) {
            return new WP_Error('update_failed', 'Update to version 1.3.4 failed: ' . $th->getMessage());
        }

    }
    /**
     * Maybe run update
     *
     * @return void
     */
    public function init(): void
    {
        $current_settings = get_option(CCPIDB_OPTIONS_NAME, []);
        $updated_settings = array_merge($current_settings, [
            'advanced' => array_merge($current_settings['advanced'] ?? [], [
                'manageSharingPermissions' => true,
                'allowDotExtension'        => true,
            ]),
        ]);

        update_option(CCPIDB_OPTIONS_NAME, $updated_settings);
    }
}
