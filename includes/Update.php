<?php

namespace CodeConfig\IDB;

use CodeConfig\IDB\Utils\Helpers;
use CodeConfig\IDB\Utils\Singleton;
defined( 'ABSPATH' ) or exit( 'Hey, what are you doing here? You silly human!' );
class Update {
    use Singleton;
    public const MIGRATION_KEYS = [
        '1.3.0' => [
            'completed'                  => 'ccpidb_update_1_3_0_completed',
            'shortcodes_table'           => 'ccpidb_shortcodes_table_migrated_1_3_0',
            'shortcodes_data'            => 'ccpidb_shortcodes_data_migrated_1_3_0',
            'user_access_table'          => 'ccpidb_user_access_table_migrated_1_3_0',
            'user_access_data'           => 'ccpidb_user_access_data_migrated_1_3_0',
            'files_table'                => 'ccpidb_files_table_migrated_1_3_0',
            'files_data'                 => 'ccpidb_files_data_migrated_1_3_0',
            'logs_table'                 => 'ccpidb_logs_table_created_1_3_0',
            'options'                    => 'ccpidb_options_migrated_1_3_0',
            'settings'                   => 'ccpidb_settings_migrated_1_3_0',
            'accounts_table'             => 'ccpidb_accounts_table_created_1_3_0',
            'accounts_data'              => 'ccpidb_accounts_data_migrated_1_3_0',
            'media_library_premium_only' => 'ccpidb_media_library_migrated_1_3_0',
            'rewrite_rules'              => 'ccpidb_flush_rewrite_rules_1_3_0',
        ],
    ];

    private static $update_list = [
        '1.2.0',
        '1.2.9',
        '1.2.16',
        '1.2.18',
        '1.3.0',
        '1.3.2',
        '1.3.4',
        '1.3.5',
        '1.3.7',
        '1.3.10'
    ];

    public function __construct() {
        $this->migrationWidgets();
        add_action( 'wp_ajax_ccpidb_retry_migration', [$this, 'migrationRetry'] );
        add_action( 'admin_init', [$this, 'checkAndRunMigrationIfNeeded'] );
    }

    public function checkUpdates() : void {
        if ( $this->isUpdateAvailable() ) {
            $this->performUpdates();
        }
    }

    /**
     * Check if an update is available for the plugin
     *
     * @return bool
     */
    public function isUpdateAvailable() : bool {
        $installedVersion = Helpers::getInstalledVersion();
        if ( !$installedVersion ) {
            return false;
        }
        foreach ( self::$update_list as $version ) {
            if ( version_compare( $version, $installedVersion, '>' ) && version_compare( $version, CCPIDB_VERSION, '<=' ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Performs updates for the plugin by iterating over the list of available
     * version update scripts. For each version that is greater than the installed
     * version and less than or equal to the current version, it includes the
     * respective update script and updates the version option in the database.
     *
     * @return void
     */
    public function performUpdates() : void {
        $installedVersion = Helpers::getInstalledVersion();
        if ( !$installedVersion ) {
            return;
        }
        foreach ( self::$update_list as $version ) {
            if ( version_compare( $version, $installedVersion, '>' ) && version_compare( $version, CCPIDB_VERSION, '<=' ) ) {
                $filePath = CCPIDB_UPDATES . "/class-update-{$version}.php";
                if ( file_exists( $filePath ) ) {
                    include_once $filePath;
                    if ( class_exists( "CodeConfig\\IDB\\Updates\\Update_" . str_replace( '.', '_', $version ) ) ) {
                        $updateClass = "CodeConfig\\IDB\\Updates\\Update_" . str_replace( '.', '_', $version );
                        $updateInstance = $updateClass::getInstance();
                        $update = $updateInstance->run_update();
                        if ( is_wp_error( $update ) || empty( $update ) ) {
                            // Stop further updates if an error occurs
                            break;
                        }
                        if ( $update && method_exists( $updateInstance, 'run_update' ) ) {
                            update_option( 'ccpidb_version', $version );
                        }
                    }
                }
            }
        }
        update_option( 'ccpidb_version', CCPIDB_VERSION );
    }

    public function migrationWidgets() {
        if ( !isset( self::MIGRATION_KEYS[CCPIDB_VERSION] ) ) {
            return;
        }
        $first_installed_version = get_option( 'ccpidb_first_installed_version', '0.0.0' );
        if ( version_compare( $first_installed_version, CCPIDB_VERSION, '==' ) ) {
            return;
        }
        add_action( 'wp_dashboard_setup', function () {
            wp_add_dashboard_widget( 'ccpidb_migration_status_widget', 'File Manager For Dropbox - Migration Status', [$this, 'renderMigrationStatusWidget'] );
        } );
    }

    public function renderMigrationStatusWidget() {
        $version = CCPIDB_VERSION;
        $migration_keys = self::MIGRATION_KEYS[$version] ?? [];
        $nonce = wp_create_nonce( 'ccpidb_migration_retry_nonce' );
        if ( empty( $migration_keys ) ) {
            echo '<p>No migration information available for this version.</p>';
            return;
        }
        echo '<div class="ccpidb-migration-widget" data-ccpidb-migration-nonce="' . esc_attr( $nonce ) . '">';
        $allCompleted = false;
        foreach ( $migration_keys as $key => $option_name ) {
            $message = $this->getMigrationMessage( $key, $version );
            if ( !$message ) {
                continue;
            }
            $is_completed = get_option( $option_name );
            if ( $key === 'completed' && $is_completed ) {
                $allCompleted = true;
            }
            echo '<div class="ccpidb-migration-card">';
            echo '<div class="ccpidb-card-header">';
            echo '<span class="ccpidb-card-title">' . esc_html( $message['title'] ) . '</span>';
            if ( $is_completed ) {
                echo '<span class="ccpidb-status ccpidb-status-success">✓</span>';
            } else {
                if ( $message['option'] === 'ccpidb_media_library_migrated_1_3_0' ) {
                    echo '<span class="ccpidb-status ccpidb-status-success">✓</span>';
                    echo '</div>';
                    echo '<div class="ccpidb-card-body">';
                    echo '<p class="ccpidb-status-text success">Media library migration is not required for the premium version.</p>';
                    echo '</div>';
                    echo '</div>';
                    continue;
                } elseif ( $message['option'] === 'ccpidb_update_1_3_0_completed' ) {
                    echo '<span class="ccpidb-status ccpidb-status-error dashicons dashicons-warning"></span>';
                    echo '</div>';
                    echo '<div class="ccpidb-card-body">';
                    echo '<p class="ccpidb-status-text error">Unfortunately, not all migrations are completed. Please retry now.</p>';
                    echo '<button class="button button-primary ccpidb-migration-retry-btn" data-ccpidb-migration-key="' . esc_attr( $key ) . '">Retry Migration</button>';
                    echo '</div>';
                    echo '</div>';
                    continue;
                } else {
                    echo '<span class="ccpidb-status ccpidb-status-error">✕</span>';
                }
            }
            echo '</div>';
            echo '<div class="ccpidb-card-body">';
            if ( !$is_completed ) {
                echo '<p class="ccpidb-status-text error">' . esc_html( $message['error_message'] ) . '</p>';
                if ( $allCompleted ) {
                    echo '<button class="button button-primary ccpidb-migration-retry-btn" data-ccpidb-migration-key="' . esc_attr( $key ) . '">Retry Migration</button>';
                }
            }
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
    }

    public function getMigrationMessage( $key, $version = CCPIDB_VERSION ) {
        $messages = [
            '1.3.0' => [
                'completed'                  => [
                    'option'          => 'ccpidb_update_1_3_0_completed',
                    'title'           => 'Overall Update Status (v1.3.0)',
                    'success_message' => 'Version 1.3.0 update completed successfully.',
                    'error_message'   => 'Version 1.3.0 update is not completed.',
                ],
                'shortcodes_table'           => [
                    'option'          => 'ccpidb_shortcodes_table_migrated_1_3_0',
                    'title'           => 'Shortcodes Table Migration',
                    'success_message' => 'Shortcodes table migrated successfully.',
                    'error_message'   => 'Shortcodes table migration is pending.',
                ],
                'shortcodes_data'            => [
                    'option'          => 'ccpidb_shortcodes_data_migrated_1_3_0',
                    'title'           => 'Shortcodes Data Migration',
                    'success_message' => 'Shortcodes data migrated successfully.',
                    'error_message'   => 'Shortcodes data migration is pending.',
                ],
                'user_access_table'          => [
                    'option'          => 'ccpidb_user_access_table_migrated_1_3_0',
                    'title'           => 'User Access Table Migration',
                    'success_message' => 'User access table migrated successfully.',
                    'error_message'   => 'User access table migration is pending.',
                ],
                'user_access_data'           => [
                    'option'          => 'ccpidb_user_access_data_migrated_1_3_0',
                    'title'           => 'User Access Data Migration',
                    'success_message' => 'User access data migrated successfully.',
                    'error_message'   => 'User access data migration is pending.',
                ],
                'files_table'                => [
                    'option'          => 'ccpidb_files_table_migrated_1_3_0',
                    'title'           => 'Files Table Migration',
                    'success_message' => 'Files table migrated successfully.',
                    'error_message'   => 'Files table migration is pending.',
                ],
                'files_data'                 => [
                    'option'          => 'ccpidb_files_data_migrated_1_3_0',
                    'title'           => 'Files Data Migration',
                    'success_message' => 'Files data migrated successfully.',
                    'error_message'   => 'Files data migration is pending.',
                ],
                'logs_table'                 => [
                    'option'          => 'ccpidb_logs_table_created_1_3_0',
                    'title'           => 'Logs Table Creation',
                    'success_message' => 'Logs table created successfully.',
                    'error_message'   => 'Logs table has not been created.',
                ],
                'options'                    => [
                    'option'          => 'ccpidb_options_migrated_1_3_0',
                    'title'           => 'Plugin Options Migration',
                    'success_message' => 'Plugin options migrated successfully.',
                    'error_message'   => 'Plugin options migration is pending.',
                ],
                'settings'                   => [
                    'option'          => 'ccpidb_settings_migrated_1_3_0',
                    'title'           => 'Plugin Settings Migration',
                    'success_message' => 'Plugin settings migrated successfully.',
                    'error_message'   => 'Plugin settings migration is pending.',
                ],
                'accounts_table'             => [
                    'option'          => 'ccpidb_accounts_table_created_1_3_0',
                    'title'           => 'Accounts Table Creation',
                    'success_message' => 'Accounts table created successfully.',
                    'error_message'   => 'Accounts table has not been created.',
                ],
                'accounts_data'              => [
                    'option'          => 'ccpidb_accounts_data_migrated_1_3_0',
                    'title'           => 'Accounts Data Migration',
                    'success_message' => 'Accounts data migrated successfully.',
                    'error_message'   => 'Accounts data migration is pending.',
                ],
                'media_library_premium_only' => [
                    'option'          => 'ccpidb_media_library_migrated_1_3_0',
                    'title'           => 'Media Library Integration Migration',
                    'success_message' => 'Media library integration migrated successfully.',
                    'error_message'   => 'Media library migration is pending.',
                ],
                'rewrite_rules'              => [
                    'option'          => 'ccpidb_flush_rewrite_rules_1_3_0',
                    'title'           => 'Rewrite Rules Flush',
                    'success_message' => 'Rewrite rules flushed successfully.',
                    'error_message'   => 'Rewrite rules have not been flushed.',
                ],
            ],
        ];
        return $messages[$version][$key] ?? null;
    }

    public function migrationRetry() : void {
        if ( empty( $_POST['_wpnonce'] ) ) {
            wp_send_json_error( __( 'Invalid request.', 'integrate-dropbox' ) );
        }
        $nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );
        if ( !wp_verify_nonce( $nonce, 'ccpidb_migration_retry_nonce' ) ) {
            wp_send_json_error( __( 'Nonce verification failed.', 'integrate-dropbox' ) );
        }
        $this->runMigrationTask();
        ob_start();
        $this->renderMigrationStatusWidget();
        $html = ob_get_clean();
        wp_send_json_success( [
            'message' => __( 'Migration retried successfully.', 'integrate-dropbox' ),
            'html'    => $html,
        ] );
    }

    public function checkAndRunMigrationIfNeeded() {
        $migration_key = self::MIGRATION_KEYS[CCPIDB_VERSION] ?? [];
        if ( empty( $migration_key ) ) {
            return;
        }
        $first_installed_version = get_option( 'ccpidb_first_installed_version', '0.0.0' );
        if ( version_compare( $first_installed_version, CCPIDB_VERSION, '==' ) ) {
            return;
        }
        $is_completed = get_option( $migration_key['completed'] );
        if ( $is_completed ) {
            return;
        }
        $this->runMigrationTask();
    }

    private function runMigrationTask() {
        $migration_keys = self::MIGRATION_KEYS[CCPIDB_VERSION] ?? [];
        if ( empty( $migration_keys ) ) {
            wp_send_json_error( __( 'No migration steps found for this version.', 'integrate-dropbox' ) );
        }
        $file_path = CCPIDB_UPDATES . '/class-update-' . CCPIDB_VERSION . '.php';
        if ( !file_exists( $file_path ) ) {
            wp_send_json_error( __( 'Update file not found.', 'integrate-dropbox' ) );
        }
        include_once $file_path;
    }

}
