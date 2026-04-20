<?php

namespace CodeConfig\IDB\Updates;

use CodeConfig\IDB\Integrations\MediaLibrary__premium_only;
use CodeConfig\IDB\Utils\Singleton;
use Exception;
use WP_Error;
defined( 'ABSPATH' ) or exit( 'Hey, what are you doing here? You silly human!' );
/**
 * Update class for version 1.3.7
 *
 * Handles database migrations and data format updates for version 1.3.7.
 * This includes table structure updates, option migrations, and data format changes.
 *
 * @package CodeConfig\IDB\Updates
 * @version 1.3.7
 * @since 1.3.7
 */
class Update_1_3_7 {
    use Singleton;
    /**
     * Constructor - Initialize all migrations
     *
     * @throws Exception If critical migrations fail
     */
    public function run_update() {
        try {
            if ( version_compare( get_option( 'ccpidb_version', '0.0.0' ), CCPIDB_VERSION, '>=' ) ) {
                return;
            }
            return '1.3.7';
        } catch ( Exception $th ) {
            return new WP_Error('update_failed', 'Update to version 1.3.7 failed: ' . $th->getMessage());
        }
    }

}
