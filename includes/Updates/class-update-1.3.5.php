<?php

namespace CodeConfig\IDB\Updates;

use CodeConfig\IDB\Utils\Singleton;
use Exception;
use WP_Error;

defined('ABSPATH') or exit('Hey, what are you doing here? You silly human!');

/**
 * Update class for version 1.3.5
 *
 * Handles database migrations and data format updates for version 1.3.5.
 * This includes table structure updates, option migrations, and data format changes.
 *
 * @package CodeConfig\IDB\Updates
 * @version 1.3.5
 * @since 1.3.5
 */
class Update_1_3_5
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

            add_action('init', [$this, 'rewrite_rules']);

            return '1.3.5';
        } catch (Exception $th) {
            return new WP_Error(400, 'Update to version 1.3.5 failed: ' . $th->getMessage());
        }

    }

    /**
     * Rewrite rules
     *
     * @return void
     */
    public function rewrite_rules(): void
    {
        add_rewrite_rule(
            '^ccpidb/([^/]+)/([^/]+)/([^/]+)\.([^/]+)$',
            'index.php?ccpidb-action=$matches[1]&ccpidb-key=$matches[2]&ccpidb-name=$matches[3]&ccpidb-ext=$matches[4]',
            'top'
        );

        flush_rewrite_rules();
    }
}
