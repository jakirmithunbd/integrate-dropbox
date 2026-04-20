<?php

namespace CodeConfig\IDB\Updates;

use function CodeConfig\IDB\ccpidb_fs;
use CodeConfig\IDB\Utils\Singleton;
use Exception;
use WP_Error;
defined( 'ABSPATH' ) or exit( 'Hey, what are you doing here? You silly human!' );
/**
 * Update class for version 1.3.2
 *
 * Handles database migrations and data format updates for version 1.3.0.
 * This includes table structure updates, option migrations, and data format changes.
 *
 * @package CodeConfig\IDB\Updates
 * @version 1.3.2
 * @since 1.3.2
 */
class Update_1_3_2 {
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
            add_action( 'init', [$this, 'rewrite_rules'] );
            add_action( 'admin_init', [$this, 'init'] );
            return '1.3.2';
        } catch ( Exception $th ) {
            return new WP_Error('update_failed', 'Update to version 1.3.2 failed: ' . $th->getMessage());
        }
    }

    /**
     * Maybe run update
     *
     * @return void
     */
    public function init() : void {
        // Migrate sync existing dropbox files
        $this->sync_existing_dropbox_files();
    }

    /**
     * Rewrite rules
     *
     * @return void
     */
    public function rewrite_rules() : void {
        add_rewrite_rule( '^ccpidb/([^/]+)/([^/]+)/([^/]+)/([^/]+)$', 'index.php?ccpidb-action=$matches[1]&ccpidb-key=$matches[2]&ccpidb-name=$matches[3]&ccpidb-ext=$matches[4]', 'top' );
        flush_rewrite_rules();
    }

    /**
     * Migrate sync existing dropbox files
     *
     * @return void
     */
    private function sync_existing_dropbox_files() : void {
        wp_schedule_single_event( time() + 10, 'ccpidb_sync_existing_dropbox_files_event' );
    }

}
