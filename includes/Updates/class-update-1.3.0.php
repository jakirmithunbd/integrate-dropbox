<?php

namespace CodeConfig\IDB\Updates;

use CodeConfig\IDB\AdminNotice;
use CodeConfig\IDB\App\Account;
use CodeConfig\IDB\App\Accounts;
use CodeConfig\IDB\App\App;
use CodeConfig\IDB\App\Client;
use CodeConfig\IDB\App\File;
use function CodeConfig\IDB\ccpidb_fs;
use CodeConfig\IDB\Integrations\MediaLibrary__premium_only;
use CodeConfig\IDB\Models\Files;
use CodeConfig\IDB\Models\Notices;
use CodeConfig\IDB\Utils\Helpers;
use CodeConfig\IDB\Utils\Singleton;
use CodeConfig\Legacy\AccessToken;
use CodeConfig\Legacy\Account as LegacyAccount;
use CodeConfig\Legacy\Authorization;
use CodeConfig\Legacy\Entry;
use Exception;
use function in_array;
use function intval;
use function is_array;
use function is_string;
use WP_Error;
use WP_Query;
defined( 'ABSPATH' ) or exit( 'Hey, what are you doing here? You silly human!' );
/**
 * Update class for version 1.3.0
 *
 * Handles database migrations and data format updates for version 1.3.0.
 * This includes table structure updates, option migrations, and data format changes.
 *
 * @package CodeConfig\IDB\Updates
 * @version 1.3.0
 * @since 1.3.0
 */
class Update_1_3_0 {
    use Singleton;
    public const MIGRATION_KEYS = [
        'completed'         => 'ccpidb_update_1_3_0_completed',
        'shortcodes_table'  => 'ccpidb_shortcodes_table_migrated_1_3_0',
        'shortcodes_data'   => 'ccpidb_shortcodes_data_migrated_1_3_0',
        'user_access_table' => 'ccpidb_user_access_table_migrated_1_3_0',
        'user_access_data'  => 'ccpidb_user_access_data_migrated_1_3_0',
        'files_table'       => 'ccpidb_files_table_migrated_1_3_0',
        'files_data'        => 'ccpidb_files_data_migrated_1_3_0',
        'logs_table'        => 'ccpidb_logs_table_created_1_3_0',
        'options'           => 'ccpidb_options_migrated_1_3_0',
        'settings'          => 'ccpidb_settings_migrated_1_3_0',
        'accounts_table'    => 'ccpidb_accounts_table_created_1_3_0',
        'accounts_data'     => 'ccpidb_accounts_data_migrated_1_3_0',
        'media_library'     => 'ccpidb_media_library_migrated_1_3_0',
        'rewrite_rules'     => 'ccpidb_flush_rewrite_rules_1_3_0',
    ];

    /**
     * Constructor - Initialize all migrations
     *
     * @throws Exception If critical migrations fail
     */
    public function run_update() {
        $migrationKey = self::MIGRATION_KEYS['completed'];
        if ( !$this->needs_migration() ) {
            update_option( $migrationKey, true );
            return;
        }
        if ( !class_exists( AccessToken::class ) ) {
            require_once CCPIDB_PATH . 'legacy/AccessToken.php';
        }
        if ( !class_exists( Entry::class ) ) {
            require_once CCPIDB_PATH . 'legacy/Entry.php';
        }
        if ( !class_exists( LegacyAccount::class ) ) {
            require_once CCPIDB_PATH . 'legacy/Account.php';
        }
        if ( !class_exists( Authorization::class ) ) {
            require_once CCPIDB_PATH . 'legacy/Authorization.php';
        }
        if ( wp_doing_ajax() ) {
            $this->mediaLibraryMigration();
            $this->setRewriteRules();
        } else {
            add_action( 'admin_init', [$this, 'mediaLibraryMigration'] );
            add_action( 'admin_init', [$this, 'setRewriteRules'] );
        }
        try {
            $this->create_logs_table();
            $this->migrate_options();
            $this->migrate_settings();
            $this->migrate_shortcodes_table();
            $this->migration_shortcodes();
            $this->migrate_files_table();
            $this->migration_files();
            $this->migrate_user_access_table();
            $this->migration_user_access_data();
            $this->create_accounts_table();
            $this->migrate_accounts();
            // Mark migration as completed
            if ( !$this->needs_migration() ) {
                update_option( $migrationKey, true );
                AdminNotice::getInstance()->addNotice(
                    'ccpidb_update_1_3_0_success',
                    __( 'Integrate Dropbox has been successfully updated to version 1.3.0.', 'integrate-dropbox' ),
                    [],
                    'success',
                    true,
                    'admin_notices',
                    []
                );
            } else {
                update_option( $migrationKey, false );
            }
            return '1.3.0';
        } catch ( Exception $e ) {
            Notices::getInstance()->add( [
                'type'    => 'error',
                'message' => 'Update 1.3.0 Migration Error: ' . $e->getMessage(),
            ] );
            return new WP_Error('update_failed', 'Update to version 1.3.0 failed: ' . $e->getMessage());
        }
    }

    /**
     * Check if migrations are needed
     *
     * @return bool True if migrations are needed, false otherwise
     */
    private function needs_migration() : bool {
        foreach ( self::MIGRATION_KEYS as $key => $option_name ) {
            if ( $key === 'completed' ) {
                continue;
            }
            if ( !get_option( $option_name, false ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Migrate options from old format to new format
     *
     * @return bool
     */
    public function migrate_options() {
        $migration_key = self::MIGRATION_KEYS['options'];
        if ( get_option( $migration_key ) ) {
            return true;
        }
        $rename_map = [
            'indbox-admin-notice'   => 'ccpidb-admin-notice',
            'indbox_install_time'   => 'ccpidb_install_time',
            'indbox_version'        => 'ccpidb_version',
            'indbox_encryption_key' => 'ccpidb_encryption_key',
        ];
        foreach ( $rename_map as $old_key => $new_key ) {
            $value = get_option( $old_key, null );
            if ( $value !== null && !get_option( $new_key ) ) {
                update_option( $new_key, $value );
            }
        }
        update_option( $migration_key, true );
        return true;
    }

    /**
     * Migrate shortcodes table structure
     *
     * @return bool
     * @throws Exception If table migration fails
     */
    public function migrate_shortcodes_table() {
        $migration_key = self::MIGRATION_KEYS['shortcodes_table'];
        if ( get_option( $migration_key ) ) {
            return false;
        }
        global $wpdb;
        $oldTable = "{$wpdb->prefix}indbox_shortcodes";
        $newTable = "{$wpdb->prefix}ccpidb_shortcodes";
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $oldTable ) ) !== $oldTable ) {
            return false;
        }
        try {
            // Check if table exists
            if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $newTable ) ) !== $newTable ) {
                $wpdb->query( $wpdb->prepare( "CREATE TABLE %i LIKE %i", $newTable, $oldTable ) );
                $wpdb->query( $wpdb->prepare( "INSERT INTO %i SELECT * FROM %i", $newTable, $oldTable ) );
            }
            $columns = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM %i", $newTable ) );
            if ( $wpdb->last_error ) {
                Notices::getInstance()->add( [
                    'type'    => 'error',
                    'message' => "Failed to get table columns during update to version 1.3.0. Please contact support.",
                ] );
                return false;
            }
            $column_names = wp_list_pluck( $columns, 'Field' );
            // Migrate config column to data
            if ( in_array( 'config', $column_names ) && !in_array( 'data', $column_names ) ) {
                $result = $wpdb->query( $wpdb->prepare( "ALTER TABLE %i CHANGE `config` `data` LONGTEXT DEFAULT NULL", $newTable ) );
                if ( $result === false ) {
                    Notices::getInstance()->add( [
                        'type'    => 'error',
                        'message' => 'Failed to rename config column to data during update to version 1.3.0. Please contact support.',
                    ] );
                    return false;
                }
            }
            // Add type column if missing
            if ( !in_array( 'type', $column_names ) ) {
                $result = $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD `type` VARCHAR(20) NOT NULL AFTER `title`", $newTable ) );
                if ( $result === false ) {
                    Notices::getInstance()->add( [
                        'type'    => 'error',
                        'message' => 'Failed to add type column during update to version 1.3.0. Please contact support.',
                    ] );
                }
            }
            // Update title column
            if ( in_array( 'title', $column_names ) ) {
                $result = $wpdb->query( $wpdb->prepare( "ALTER TABLE %i MODIFY `title` VARCHAR(120) DEFAULT NULL", $newTable ) );
                if ( $result === false ) {
                    Notices::getInstance()->add( [
                        'type'    => 'error',
                        'message' => 'Failed to modify title column during update to version 1.3.0. Please contact support.',
                    ] );
                }
            }
            // Update status column
            if ( in_array( 'status', $column_names ) ) {
                $result = $wpdb->query( $wpdb->prepare( "ALTER TABLE %i MODIFY `status` VARCHAR(10) DEFAULT 'on'", $newTable ) );
                if ( $result === false ) {
                    Notices::getInstance()->add( [
                        'type'    => 'error',
                        'message' => 'Failed to modify status column during update to version 1.3.0. Please contact support.',
                    ] );
                }
            }
            // Update integration column
            if ( !in_array( 'integration', $column_names ) ) {
                $result = $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD `integration` VARCHAR(60) DEFAULT NULL AFTER `type`", $newTable ) );
                if ( $result === false ) {
                    Notices::getInstance()->add( [
                        'type'    => 'error',
                        'message' => 'Failed to modify integration column during update to version 1.3.0. Please contact support.',
                    ] );
                }
            }
            update_option( $migration_key, true );
            $this->update_timestamp_columns( $newTable );
            return true;
        } catch ( Exception $e ) {
            Notices::getInstance()->add( [
                'type'    => 'error',
                'message' => 'Shortcodes table migration failed during update to version 1.3.0. Please contact support.',
            ] );
            update_option( $migration_key, false );
            return false;
        }
    }

    /**
     * Migrate files table structure
     *
     * @return bool
     * @throws Exception If table migration fails
     */
    public function migrate_files_table() {
        $migration_key = self::MIGRATION_KEYS['files_table'];
        if ( get_option( $migration_key ) ) {
            return true;
        }
        global $wpdb;
        $oldTable = "{$wpdb->prefix}indbox_files";
        $newTable = "{$wpdb->prefix}ccpidb_files";
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $oldTable ) ) !== $oldTable ) {
            return false;
        }
        try {
            // Check if table exists
            if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $newTable ) ) !== $newTable ) {
                $wpdb->query( $wpdb->prepare( "CREATE TABLE %i LIKE %i", $newTable, $oldTable ) );
                $wpdb->query( $wpdb->prepare( "INSERT INTO %i SELECT * FROM %i", $newTable, $oldTable ) );
            }
            $columns = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM %i", $newTable ) );
            if ( $wpdb->last_error ) {
                Notices::getInstance()->add( [
                    'type'    => 'error',
                    'message' => "Failed to get table columns during update to version 1.3.0. Please contact support.",
                ] );
                return false;
            }
            $column_names = wp_list_pluck( $columns, 'Field' );
            $oldColumns = [
                'file_id',
                'parent_id',
                'account_id',
                'type',
                'thumbnail_size',
                'data',
                'created',
                'updated',
                'is_computers',
                'is_shared_with_me',
                'is_starred',
                'is_shared_drive',
                'preview',
                'download'
            ];
            if ( array_intersect( $oldColumns, $column_names ) ) {
                $wpdb->query( $wpdb->prepare( "ALTER TABLE %i\n                        CHANGE `file_id` `fileId` VARCHAR(120) NOT NULL AFTER `id`,\n                        ADD `fileKey` VARCHAR(120) NOT NULL AFTER `fileId`,\n                        ADD `path` TEXT NOT NULL AFTER `fileKey`,\n                        CHANGE `parent_id` `parent` TEXT NULL AFTER `size`,\n                        CHANGE `account_id` `accountId` TEXT NOT NULL AFTER `parent`,\n                        CHANGE `type` `mimeType` VARCHAR(255) NOT NULL AFTER `accountId`,\n                        CHANGE `extension` `extension` VARCHAR(60) DEFAULT NULL AFTER `mimeType`,\n                        CHANGE `thumbnail_size` `thumbnailRatio` VARCHAR(20) NULL AFTER `thumbnail`,\n                        ADD `description` LONGTEXT NULL AFTER `thumbnailRatio`,\n                        ADD `metaData` LONGTEXT NULL AFTER `description`,\n                        ADD `sharedLink` LONGTEXT NULL AFTER `metaData`,\n                        ADD `isDir` TINYINT(1) DEFAULT 0 AFTER `sharedLink`,\n                        ADD `permissions` LONGTEXT NULL AFTER `isDir`,\n                        ADD `hasOwnThumbnail` TINYINT(1) DEFAULT 0 AFTER `permissions`,\n                        ADD `icon` LONGTEXT NULL AFTER `hasOwnThumbnail`,\n                        ADD `additionalData` LONGTEXT NULL AFTER `icon`,\n                        DROP `data`,\n                        DROP `created`,\n                        DROP `updated`,\n                        DROP `is_computers`,\n                        DROP `is_shared_with_me`,\n                        DROP `is_starred`,\n                        DROP `is_shared_drive`,\n                        DROP `preview`,\n                        DROP `download`", $newTable ) );
            }
            $this->update_timestamp_columns( $newTable );
            update_option( $migration_key, true );
            return true;
        } catch ( Exception $e ) {
            Notices::getInstance()->add( [
                'type'    => 'error',
                'message' => 'Files table migration failed during update to version 1.3.0. Please contact support.',
            ] );
            return false;
        }
    }

    /**
     * Create accounts table if doesn't exist
     *
     * @return bool
     * @throws Exception If table creation fails
     */
    public function create_accounts_table() {
        $dependentMigrationKey = self::MIGRATION_KEYS['settings'];
        if ( !get_option( $dependentMigrationKey ) ) {
            $res = $this->migrate_settings();
            if ( $res === false ) {
                return false;
            }
        }
        $migration_key = self::MIGRATION_KEYS['accounts_table'];
        if ( get_option( $migration_key ) ) {
            return true;
        }
        try {
            $sql = ccpidbGetTablesDefinitions( 'accounts' );
            if ( !$sql ) {
                Notices::getInstance()->add( [
                    'type'    => 'error',
                    'message' => 'Failed to get accounts table definition during update to version 1.3.0. Please contact support.',
                ] );
            }
            if ( !function_exists( 'dbDelta' ) ) {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            }
            dbDelta( $sql );
            update_option( $migration_key, true );
            return true;
        } catch ( Exception $e ) {
            Notices::getInstance()->add( [
                'type'    => 'error',
                'message' => 'Accounts table creation failed during update to version 1.3.0. Please contact support.',
            ] );
            update_option( $migration_key, false );
            return false;
        }
    }

    /**
     * Create logs table if doesn't exist
     *
     * @return void
     * @throws Exception If table creation fails
     */
    public function create_logs_table() {
        $migration_key = self::MIGRATION_KEYS['logs_table'];
        if ( get_option( $migration_key ) ) {
            return;
        }
        try {
            $sql = ccpidbGetTablesDefinitions( 'logs' );
            if ( !$sql ) {
                Notices::getInstance()->add( [
                    'type'    => 'error',
                    'message' => 'Failed to get logs table definition during update to version 1.3.0. Please contact support.',
                ] );
                return;
            }
            if ( !function_exists( 'dbDelta' ) ) {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            }
            dbDelta( $sql );
            update_option( $migration_key, true );
        } catch ( Exception $e ) {
            Notices::getInstance()->add( [
                'type'    => 'error',
                'message' => 'Logs table creation failed during update to version 1.3.0. message: ' . $e->getMessage() . ' Please contact support.',
            ] );
            update_option( $migration_key, false );
        }
    }

    /**
     * Update timestamp columns from TIMESTAMP to DATETIME
     *
     * @param string $table Table name
     * @return void
     * @throws Exception If timestamp column update fails
     */
    private function update_timestamp_columns( $table ) {
        global $wpdb;
        try {
            $columns = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM %i", $table ) );
            if ( $wpdb->last_error ) {
                Notices::getInstance()->add( [
                    'type'    => 'error',
                    'message' => "Failed to get table columns during update to version 1.3.0. Please contact support.",
                ] );
                return;
            }
            $column_names = wp_list_pluck( $columns, 'Field' );
            // Handle createdAt column
            if ( in_array( 'created_at', $column_names ) ) {
                $result = $wpdb->query( $wpdb->prepare( "ALTER TABLE %i CHANGE `created_at` `createdAt` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00'", $table ) );
                if ( $result === false ) {
                    Notices::getInstance()->add( [
                        'type'    => 'error',
                        'message' => 'Failed to rename created_at to createdAt during update to version 1.3.0. Please contact support.',
                    ] );
                }
            } elseif ( !in_array( 'createdAt', $column_names ) ) {
                $result = $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD `createdAt` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00'", $table ) );
                if ( $result === false ) {
                    Notices::getInstance()->add( [
                        'type'    => 'error',
                        'message' => 'Failed to add createdAt column during update to version 1.3.0. Please contact support.',
                    ] );
                }
            }
            // Handle updatedAt column
            if ( in_array( 'updated_at', $column_names ) ) {
                $result = $wpdb->query( $wpdb->prepare( "ALTER TABLE %i CHANGE `updated_at` `updatedAt` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00'", $table ) );
                if ( $result === false ) {
                    Notices::getInstance()->add( [
                        'type'    => 'error',
                        'message' => 'Failed to rename updated_at to updatedAt during update to version 1.3.0. Please contact support.',
                    ] );
                }
            } elseif ( !in_array( 'updatedAt', $column_names ) ) {
                $result = $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD `updatedAt` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00'", $table ) );
                if ( $result === false ) {
                    Notices::getInstance()->add( [
                        'type'    => 'error',
                        'message' => 'Failed to add updatedAt column during update to version 1.3.0. Please contact support.',
                    ] );
                }
            }
        } catch ( Exception $e ) {
            Notices::getInstance()->add( [
                'type'    => 'error',
                'message' => 'Timestamp column update failed during update to version 1.3.0. Please contact support.',
            ] );
        }
    }

    /**
     * Migrate legacy accounts to new format
     *
     * @param string|null $appKey Application key
     * @param string|null $appSecret Application secret
     * @return bool
     */
    public function migrate_accounts() {
        $dependentMigrationKey = self::MIGRATION_KEYS['accounts_table'];
        if ( !get_option( $dependentMigrationKey ) ) {
            $res = $this->create_accounts_table();
            if ( $res === false ) {
                return false;
            }
        }
        $settings = get_option( 'indbox_settings', [] );
        $appKey = get_option( 'indbox-app-key' );
        $appSecret = get_option( 'indbox-app-secret' );
        $isTeam = ($settings['settings']['enableTeamFolders'] ?? '') == 'true';
        $migration_key = self::MIGRATION_KEYS['accounts_data'];
        if ( get_option( $migration_key ) ) {
            return true;
        }
        // Validate required parameters
        if ( empty( $appKey ) || empty( $appSecret ) ) {
            Notices::getInstance()->add( [
                'type'    => 'error',
                'message' => 'Account migration skipped during update to version 1.3.0 due to missing app key or secret. Please reconfigure your Dropbox app settings.',
            ] );
            return false;
        }
        $accessTokens = get_option( 'indbox_access_tokens' );
        if ( empty( $accessTokens ) ) {
            Notices::getInstance()->add( [
                'type'    => 'error',
                'message' => 'No legacy access tokens found for account migration during update to version 1.3.0.',
            ] );
            return false;
        }
        $migratedAccounts = [];
        foreach ( $accessTokens as $key => $accessToken ) {
            if ( !$accessToken instanceof AccessToken ) {
                Notices::getInstance()->add( [
                    'type'    => 'error',
                    'message' => 'No legacy access tokens found for account migration during update to version 1.3.0.',
                ] );
                continue;
            }
            try {
                $client = Client::getInstance( 'new' )->getNewClient( $accessToken, $appKey, $appSecret );
                if ( is_wp_error( $client ) ) {
                    Notices::getInstance()->add( [
                        'type'    => 'error',
                        'message' => 'Account migration failed during update to version 1.3.0',
                    ] );
                    continue;
                }
                $account = $client->getCurrentAccount();
                $storage = $client->getSpaceUsage();
                $accountData = [
                    'id'            => $account->getAccountId(),
                    'accountKey'    => $account->getAccountKey(),
                    'name'          => $account->getNameDetails(),
                    'email'         => $account->getEmail(),
                    'photo'         => $account->getProfilePhotoUrl(),
                    'rootInfo'      => $account->getRootInfo(),
                    'storage'       => $storage,
                    'country'       => $account->getCountry(),
                    'locale'        => $account->getLocale(),
                    'type'          => $account->getAccountType(),
                    'referralLink'  => $account->getReferralLink(),
                    'userId'        => get_current_user_id(),
                    'tokens'        => $accessToken->getData(),
                    'active'        => true,
                    'emailVerified' => $account->emailIsVerified(),
                    'disabled'      => $account->isDisabled(),
                    'isPaired'      => $account->isPaired(),
                    'isTeam'        => $isTeam,
                ];
                $newAccount = Accounts::getInstance()->addAccount( new Account($accountData) );
                if ( is_wp_error( $newAccount ) ) {
                    Notices::getInstance()->add( [
                        'type'    => 'error',
                        'message' => 'Account migration failed during update to version 1.3.0: ' . $newAccount->get_error_message(),
                    ] );
                    continue;
                }
                update_option( $migration_key, true );
                $migratedAccounts[] = $newAccount->getId();
            } catch ( Exception $e ) {
                update_option( $migration_key, false );
                return false;
            }
        }
        return ( empty( $migratedAccounts ) ? false : true );
    }

    /**
     * Migrate settings from old format to new format
     *
     * @return bool
     */
    public function migrate_settings() {
        $migration_key = self::MIGRATION_KEYS['settings'];
        if ( get_option( $migration_key ) ) {
            return true;
        }
        try {
            $settingOptions = get_option( 'indbox_settings', [] );
            $appKey = get_option( 'indbox-app-key' );
            $appSecret = get_option( 'indbox-app-secret' );
            $redirectUrl = get_option( 'indbox-redirect-url' );
            $activeIntegrations = [];
            $settings = $settingOptions['settings'] ?? [];
            if ( empty( $settings ) ) {
                Notices::getInstance()->add( [
                    'type'    => 'error',
                    'message' => 'No legacy settings found for migration during update to version 1.3.0.',
                ] );
                return true;
            }
            foreach ( $settings['activeIntegration'] ?? [] as $integration ) {
                switch ( $integration ) {
                    case 'gutenberg-editor':
                        $activeIntegrations[] = 'gutenberg';
                        break;
                    case 'elementor':
                        $activeIntegrations[] = 'elementor';
                        break;
                    case 'media-library':
                        $activeIntegrations[] = 'mediaLibrary';
                        break;
                    case 'cf7':
                        $activeIntegrations[] = 'contactForm7';
                        break;
                    case 'woocommerce':
                        $activeIntegrations[] = 'woocommerce';
                        break;
                    case 'master-study-lms':
                        $activeIntegrations[] = 'masterStudyLMS';
                        break;
                    case 'tutor-lms':
                        $activeIntegrations[] = 'tutorLMS';
                        break;
                }
            }
            $allow_times = [
                '5m',
                '10m',
                '15m',
                '30m',
                '1h',
                '5h',
                '1d',
                '7d',
                'custom'
            ];
            $timer = $this->convert_to_seconds( $settings['autoSyncTimerUnit']['value'] ?? '1h' );
            $customTimer = (int) $settings['autoSyncTimer'] ?? 120;
            $convertedTimer = $this->convert_to_seconds( $settings['autoSyncTimerUnit']['value'] ?? '1h' ) ?? 3600;
            if ( in_array( $timer, $allow_times ) ) {
                $timer = $convertedTimer;
            } else {
                $customTimer = $timer;
                $timer = 'custom';
            }
            $new_settings = [
                'accounts'        => [
                    'connectionType' => 'manual',
                    'appKey'         => $appKey,
                    'appSecret'      => $appSecret,
                    'redirectUri'    => $redirectUrl,
                ],
                'advanced'        => [
                    'sharingPermission'        => ($settings['sharingPermission'] ?? '') == 'true',
                    'deleteDataOnUninstall'    => false,
                    'rememberLastOpenedFolder' => ($settings['rememberLastOpenedFolder'] ?? '') == 'true',
                    'excludeIncludeFolders'    => ($settings['excludeIncludeFolders'] ?? '') == 'true',
                ],
                'appearance'      => [
                    'preloader'    => $settings['selectedPreloader'] ?? '1',
                    'primaryColor' => $settings['primaryColor'] ?? "#0061fe",
                    'customCSS'    => $settings['customCSS'] ?? '',
                ],
                'integrations'    => [
                    'activeIntegrations' => $activeIntegrations ?? [],
                    'mediaLibrary'       => [
                        'folders'                    => $this->convertFileIdToFileKey( $settings['mediaLibraryFolders'] ?? [] ),
                        'deleteCloudFile'            => ($settings['deleteCloudFile'] ?? '') == 'true',
                        'showAllFoldersMediaLibrary' => ($settings['showAllFoldersMediaLibrary'] ?? '') == 'true',
                    ],
                ],
                'userAccess'      => [
                    'createFolderOnRegistration'    => ($settings['createFolderOnRegistration'] ?? '') == 'true',
                    'privateFolderInAdminDashboard' => ($settings['privateFolderInAdminDashboard'] ?? '') == 'true',
                ],
                'synchronization' => [
                    'enableSync'  => ($settings['enableAutoSynchronization'] ?? '') == 'true',
                    'folders'     => $this->convertFileIdToFileKey( $settings['autoSyncFolders'] ?? [] ),
                    'timer'       => (string) $timer,
                    'customTimer' => (int) $customTimer,
                ],
            ];
            // Save new settings
            $result = update_option( 'ccpidb_settings', $new_settings );
            if ( !$result ) {
                Notices::getInstance()->add( [
                    'type'    => 'error',
                    'message' => 'Failed to save new settings format during update to version 1.3.0.',
                ] );
            }
            update_option( $migration_key, true );
            return true;
        } catch ( Exception $e ) {
            update_option( $migration_key, false );
            return false;
        }
    }

    private function convertFileIdToFileKey( $files ) {
        if ( !is_array( $files ) || empty( $files ) ) {
            return [];
        }
        $fileKeys = [];
        foreach ( $files as $file ) {
            if ( empty( $file['file_id'] ) || empty( $file['account_id'] ) ) {
                continue;
            }
            $fileKeys[] = ccpidbGenerateKey( $file['file_id'], $file['account_id'] );
        }
        return $fileKeys;
    }

    /**
     * Convert time string to seconds
     *
     * @param string $time_str Time string (e.g., '1h', '30m', '2d')
     * @return int|'custom' Number of seconds
     */
    private function convert_to_seconds( $time_str ) {
        if ( 'custom' === $time_str ) {
            return 'custom';
        }
        if ( !is_string( $time_str ) || empty( $time_str ) ) {
            return 0;
        }
        if ( preg_match( '/^(\\d+)([mhd])$/i', trim( $time_str ), $matches ) ) {
            $value = (int) $matches[1];
            $unit = strtolower( $matches[2] );
            switch ( $unit ) {
                case 'm':
                    return $value * 60;
                case 'h':
                    return $value * 60 * 60;
                case 'd':
                    return $value * 24 * 60 * 60;
                default:
                    return 0;
            }
        }
        return 0;
    }

    /**
     * Migrate files data format
     *
     * @return bool
     */
    public function migration_files() {
        $dependentMigrationKey = self::MIGRATION_KEYS['files_table'];
        if ( !get_option( $dependentMigrationKey ) ) {
            $res = $this->migrate_files_table();
            if ( $res === false ) {
                return false;
            }
        }
        $migration_key = self::MIGRATION_KEYS['files_data'];
        if ( get_option( $migration_key ) ) {
            return true;
        }
        global $wpdb;
        try {
            $oldTable = "{$wpdb->prefix}indbox_files";
            $newTable = "{$wpdb->prefix}ccpidb_files";
            if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $oldTable ) ) !== $oldTable ) {
                return false;
            }
            $files_data = $wpdb->get_results( $wpdb->prepare( "SELECT data, file_id as fileId, account_id as accountId, thumbnail_size FROM %i WHERE data IS NOT NULL", $oldTable ), ARRAY_A );
            if ( empty( $files_data ) ) {
                return true;
            }
            $wpdb->query( 'START TRANSACTION' );
            $updated_count = 0;
            foreach ( $files_data as $file_row ) {
                try {
                    $file_data = maybe_unserialize( $file_row['data'] );
                    if ( empty( $file_data ) || !$file_data instanceof Entry ) {
                        continue;
                    }
                    $file = $file_data->getData();
                    $fileId = $file_row['fileId'];
                    $accountId = $file_row['accountId'];
                    $thumbnailSize = $file_row['thumbnail_size'];
                    $path = $file['path'] ?? null;
                    $parent = ( $file['parent'] ?? null ?: '/' );
                    if ( empty( $fileId ) || empty( $accountId ) || $path === null || $parent === null ) {
                        continue;
                    }
                    $fileKey = ccpidbGenerateKey( $fileId, $accountId );
                    $ext = $file['extension'] ?? '';
                    $thumbnail = ( empty( $file['is_dir'] ) ? ccpidbGetUrl(
                        'thumbnail',
                        $fileKey,
                        $file['name'] ?? '',
                        'lg',
                        $ext
                    ) : null );
                    $thumbnailRatio = null;
                    if ( !empty( $thumbnailSize ) && strpos( $thumbnailSize, 'x' ) !== false ) {
                        [$width, $height] = explode( 'x', $thumbnailSize );
                        if ( !empty( $width ) && !empty( $height ) && is_numeric( $width ) && is_numeric( $height ) && $height != 0 ) {
                            $gcd = function ( $a, $b ) use(&$gcd) {
                                return ( $b == 0 ? $a : $gcd( $b, $a % $b ) );
                            };
                            $divisor = $gcd( $width, $height );
                            $w = intval( $width / $divisor );
                            $h = intval( $height / $divisor );
                            $thumbnailRatio = "{$w}:{$h}";
                        }
                    }
                    $sharedLink = str_replace( ['&raw=1', '&raw=0', 'dl=1'], ['', '', 'dl=0'], $file['preview_link'] ?? '' );
                    $fileMimeTypeOrExt = ( $file['mime_type'] ?? null ?: $file['extension'] ?? null );
                    $data = [
                        'fileKey'         => $fileKey,
                        'path'            => $path,
                        'parent'          => $parent,
                        'extension'       => ( empty( $file['is_dir'] ) ? $file['extension'] : 'folder' ),
                        'thumbnail'       => $thumbnail,
                        'thumbnailRatio'  => ( $thumbnail ? $thumbnailRatio : null ),
                        'sharedLink'      => $sharedLink,
                        'description'     => $file['description'] ?? null,
                        'isDir'           => ( (int) (!empty( $file['is_dir'] )) ? 1 : 0 ),
                        'permissions'     => serialize( $file['permissions'] ?? [] ),
                        'hasOwnThumbnail' => ( (int) (!empty( $file['has_own_thumbnail'] )) ? 1 : 0 ),
                        'icon'            => Helpers::defaultIcon( $fileMimeTypeOrExt, '128x128' ),
                        'additionalData'  => serialize( [
                            'rev'               => $file['rev'] ?? null,
                            'basename'          => $file['basename'] ?? null,
                            'clientModified'    => $file['last_edited'] ?? null,
                            'path_display'      => $file['path_display'] ?? null,
                            'canPreviewByCloud' => $file['can_preview_by_cloud'] ?? null,
                            'canEditByCloud'    => $file['can_edit_by_cloud'] ?? null,
                            'mediaInfo'         => $file['media_info'] ?? null,
                            'PF'                => $file['pf'] ?? false,
                            'hasAccess'         => $file['has_access'] ?? true,
                        ] ),
                    ];
                    $result = $wpdb->update(
                        $newTable,
                        $data,
                        [
                            'fileId'    => $file['id'],
                            'name'      => $file['name'],
                            'accountId' => $accountId,
                        ],
                        [
                            '%s',
                            '%s',
                            '%s',
                            '%s',
                            '%s',
                            '%s',
                            '%s',
                            '%d',
                            '%s',
                            '%d',
                            '%s',
                            '%s'
                        ],
                        ['%s', '%s', '%s']
                    );
                    if ( $result !== false ) {
                        $updated_count++;
                    }
                } catch ( Exception $e ) {
                    continue;
                }
            }
            $wpdb->delete( $newTable, [
                'fileId' => 'root',
            ], ['%s'] );
            $wpdb->query( 'COMMIT' );
            update_option( $migration_key, true );
            return true;
        } catch ( Exception $e ) {
            $wpdb->query( 'ROLLBACK' );
            update_option( $migration_key, false );
            return false;
        }
    }

    public function migrate_user_access_table() {
        $migration_key = self::MIGRATION_KEYS['user_access_table'];
        if ( get_option( $migration_key ) ) {
            return true;
        }
        global $wpdb;
        $oldTable = "{$wpdb->prefix}indbox_user_access";
        $newTable = "{$wpdb->prefix}ccpidb_user_access";
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $oldTable ) ) !== $oldTable ) {
            return false;
        }
        try {
            // Check if table exists
            if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $newTable ) ) !== $newTable ) {
                $wpdb->query( $wpdb->prepare( "CREATE TABLE %i LIKE %i", $newTable, $oldTable ) );
                $wpdb->query( $wpdb->prepare( "INSERT INTO %i SELECT * FROM %i", $newTable, $oldTable ) );
            }
            $newTableColumns = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM %i", $newTable ) );
            $newTableColumnsField = wp_list_pluck( $newTableColumns, 'Field' );
            if ( $wpdb->last_error ) {
                Notices::getInstance()->add( [
                    'type'    => 'error',
                    'message' => "Failed to get table columns during update to version 1.3.0. Please contact support.",
                ] );
                return false;
            }
            if ( !in_array( 'force', $newTableColumnsField ) ) {
                update_option( $migration_key, true );
                return true;
            }
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i\n                        ADD `pages` LONGTEXT DEFAULT NULL AFTER `folders`,\n                        DROP `force`", $newTable ) );
            $this->update_timestamp_columns( $newTable );
            update_option( $migration_key, true );
            return true;
        } catch ( Exception $e ) {
            Notices::getInstance()->add( [
                'type'    => 'error',
                'message' => 'User access table migration failed during update to version 1.3.0. Please contact support.',
            ] );
            return false;
        }
    }

    public function migration_shortcodes() {
        $dependentMigration = self::MIGRATION_KEYS['shortcodes_table'];
        if ( !get_option( $dependentMigration ) ) {
            $res = $this->migrate_shortcodes_table();
            if ( $res === false ) {
                return false;
            }
        }
        $migration_key = self::MIGRATION_KEYS['shortcodes_data'];
        if ( get_option( $migration_key ) ) {
            return true;
        }
        global $wpdb;
        try {
            $oldTable = "{$wpdb->prefix}indbox_shortcodes";
            $newTable = "{$wpdb->prefix}ccpidb_shortcodes";
            if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $oldTable ) ) !== $oldTable ) {
                return false;
            }
            $shortcodes_data = $wpdb->get_results( $wpdb->prepare( "SELECT id, config as data,status FROM %i", $oldTable ), ARRAY_A );
            if ( empty( $shortcodes_data ) ) {
                return false;
            }
            $wpdb->query( 'START TRANSACTION' );
            $updated_count = 0;
            foreach ( $shortcodes_data as $shortcode_row ) {
                try {
                    $old_data = maybe_unserialize( $shortcode_row['data'] );
                    $status = ( $shortcode_row['status'] === 'on' ? 'active' : 'inactive' );
                    if ( empty( $old_data ) || !is_array( $old_data ) ) {
                        continue;
                    }
                    $new_data = $this->migration_shortcode( $old_data );
                    $type = $this->migration_shortcode_type( $old_data['type'] ?? '' );
                    $update_data = [
                        'data'        => serialize( $new_data ),
                        'status'      => $status,
                        'integration' => ( !empty( $old_data['cf7Data'] ) ? 'contactForm7' : '' ),
                        'type'        => $type,
                    ];
                    $result = $wpdb->update(
                        $newTable,
                        $update_data,
                        [
                            'id' => $shortcode_row['id'],
                        ],
                        [
                            '%s',
                            '%s',
                            '%s',
                            '%s'
                        ],
                        ['%d']
                    );
                    if ( $result !== false ) {
                        $updated_count++;
                    }
                } catch ( Exception $e ) {
                    continue;
                }
            }
            $wpdb->query( 'COMMIT' );
            update_option( $migration_key, true );
            return true;
        } catch ( Exception $e ) {
            $wpdb->query( 'ROLLBACK' );
            update_option( $migration_key, false );
            return false;
        }
    }

    private function migration_shortcode_type( string $oldType ) : string {
        switch ( $oldType ) {
            case 'File Browser':
                return 'file-browser';
            case 'CF7 File Uploader':
            case 'File Uploader':
                return 'file-uploader';
            case 'Media Player':
                return 'media-player';
            case 'Gallery':
                return 'gallery';
            case 'Slider Carousel':
                return 'slider-carousel';
            case 'Embed Documents':
                return 'embed-documents';
            case 'Search Box':
                return 'search-box';
            case 'Download Links':
            case 'View Links':
                return 'file-list';
            default:
                return 'gallery';
        }
    }

    private function migration_shortcode( array $old ) : array {
        /**
         * ----------------------------------------------------
         * SOURCE
         * ----------------------------------------------------
         */
        $source = [
            'fileKeys' => [],
        ];
        if ( !empty( $old['folders'] ) && is_array( $old['folders'] ) ) {
            foreach ( $old['folders'] as $folder ) {
                $file = [
                    'fileKey'      => ccpidbGenerateKey( $folder['file_id'], $folder['account_id'] ),
                    'thumbnailKey' => '',
                ];
                if ( !empty( $folder['indbox_poster'] ) ) {
                    $file['thumbnailKey'] = ccpidbGenerateKey( $folder['indbox_poster']['file_id'], $folder['indbox_poster']['account_id'] );
                }
                $source['fileKeys'][] = $file;
            }
        }
        /**
         * ----------------------------------------------------
         * FILTER
         * ----------------------------------------------------
         */
        $extensions = [];
        $except_extensions = [];
        if ( in_array( $old['type'], ['File Uploader', 'Gallery'] ) ) {
            if ( !empty( $old['allowExtensions'] ) ) {
                $extensions = array_values( array_filter( array_map( 'trim', explode( ',', $old['allowExtensions'] ) ) ) );
            }
            if ( !empty( $old['allowExceptExtensions'] ) ) {
                $except_extensions = array_values( array_filter( array_map( 'trim', explode( ',', $old['allowExceptExtensions'] ) ) ) );
                $extensions = array_diff( $extensions, $except_extensions );
            }
        }
        $filter = [
            'extension' => [
                'include' => $extensions,
                'exclude' => $except_extensions,
                'all'     => ($old['allowAllExtensions'] ?? '') == 'true',
            ],
            'name'      => [
                'include' => $old['allowNames'] ?? '',
                'exclude' => $old['allowExceptNames'] ?? '',
                'all'     => ($old['allowAllNames'] ?? '') == 'true',
                'applyTo' => [
                    'files'   => ($old['showFiles'] ?? '') == 'true',
                    'folders' => ($old['showFolders'] ?? '') == 'true',
                ],
            ],
        ];
        if ( $old['type'] === 'File Uploader' || $old['type'] === 'File Browser' ) {
            $filter['upload'] = [
                'maxSize'  => $old['maxFileSize'] ?? 0,
                'minSize'  => $old['minFileSize'] ?? 0,
                'maxFiles' => $old['maxFiles'] ?? 0,
            ];
        }
        /**
         * ----------------------------------------------------
         * NOTIFICATIONS
         * ----------------------------------------------------
         */
        $notifications = [
            'enable'            => [],
            'emailRecipients'   => '',
            'skipCurrentUser'   => false,
            'new_folder'        => false,
            'rename'            => false,
            'create_share_link' => false,
            'view_share_file'   => false,
            'move'              => false,
            'copy'              => false,
            'delete'            => false,
            'upload'            => false,
            'download'          => false,
        ];
        /**
         * ----------------------------------------------------
         * PERMISSIONS (Exact structure)
         * ----------------------------------------------------
         */
        $permissions = [
            'newFolder'       => [
                'enable'           => ($old['creatFolderButtonInHeader'] ?? '') == 'true',
                'userAccess'       => 'everyone',
                'loggedInUserType' => 'roles',
                'displayFor'       => [],
            ],
            'upload'          => [
                'enable'           => ($old['fileUploaderInHeader'] ?? '') == 'true',
                'folderUpload'     => false,
                'userAccess'       => 'everyone',
                'loggedInUserType' => 'users',
                'displayFor'       => [],
            ],
            'preview'         => [
                'enable'           => true,
                'inline'           => true,
                'popOut'           => false,
                'previewThumbnail' => true,
                'userAccess'       => 'everyone',
                'loggedInUserType' => 'users',
                'displayFor'       => [],
            ],
            'rename'          => [
                'enable'           => ($old['allowUserRename'] ?? '') == 'true',
                'userAccess'       => 'everyone',
                'loggedInUserType' => 'roles',
                'displayFor'       => [],
            ],
            'download'        => [
                'enable'           => ($old['allowUserFileDownload'] ?? '') == 'true',
                'folderDownload'   => false,
                'multipleDownload' => false,
                'userAccess'       => 'everyone',
                'loggedInUserType' => 'roles',
                'displayFor'       => [],
            ],
            'copy'            => [
                'enable'           => false,
                'userAccess'       => 'everyone',
                'loggedInUserType' => 'users',
                'displayFor'       => [],
            ],
            'move'            => [
                'enable'           => false,
                'userAccess'       => 'everyone',
                'loggedInUserType' => 'users',
                'displayFor'       => [],
            ],
            'share'           => [
                'enable'           => false,
                'userAccess'       => 'everyone',
                'loggedInUserType' => 'users',
                'displayFor'       => [],
            ],
            'search'          => [
                'enable'           => ($old['searchBoxInHeader'] ?? '') == 'true',
                'searchLocation'   => [
                    'cache'  => true,
                    'server' => true,
                ],
                'searchScope'      => [
                    'current' => true,
                    'global'  => true,
                ],
                'userAccess'       => 'everyone',
                'loggedInUserType' => 'users',
                'displayFor'       => [],
            ],
            'delete'          => [
                'enable'           => ($old['allowUserDelete'] ?? '') == 'true',
                'userAccess'       => 'everyone',
                'loggedInUserType' => 'users',
                'displayFor'       => [],
            ],
            'passwordProtect' => [
                'enable'   => false,
                'password' => '',
            ],
            'displayFor'      => [
                'whoCanViewModule'        => strtolower( $old['whoCanViewModule'] ?? 'everyone' ),
                'loggedInUserType'        => strtolower( $old['loggedInUserType'] ?? 'users' ),
                'displayFor'              => array_filter( array_map( fn( $user ) => $user['value'] ?? null, ( !empty( $old['displayForUsers'] ) && is_array( $old['displayForUsers'] ) ? $old['displayForUsers'] : [] ) ) ),
                'showAccessDeniedMessage' => true,
                'accessDeniedMessage'     => 'You do not have access to this module.',
            ],
        ];
        /**
         * ----------------------------------------------------
         * ADVANCED
         * ----------------------------------------------------
         */
        $aWidth = $this->parseCssValue( $old['width'] ?? '100%', '%' );
        $aHeight = $this->parseCssValue( $old['height'] ?? 'auto', 'auto' );
        $advanced = [
            'width'               => [
                'value' => $aWidth['value'] ?? 100,
                'unit'  => $aWidth['unit'] ?? '%',
            ],
            'height'              => [
                'value' => $aHeight['value'] ?? 'auto',
                'unit'  => $aHeight['unit'] ?? 'auto',
            ],
            'theme'               => strtolower( $old['theme'] ?? 'light' ),
            'borderBoxVisibility' => ($old['borderBoxVisibility'] ?? '') == 'true',
            'files'               => [
                'loadingType' => strtolower( $old['filesLoadingType'] ?? 'load_more' ),
                'perPage'     => intval( $old['fileNumbersInFirstRender'] ?? 20 ),
            ],
            'autoFetch'           => [
                'status'   => ($old['autoFetch'] ?? '') == 'true',
                'interval' => intval( $old['autoFetchInterval'] ?? 60 ),
            ],
            'sort'                => [
                'orderBy' => ( ($old['sort']['sortBy'] ?? '') == 'Created Date' ? 'createdAt' : 'name' ),
                'order'   => ( strtoupper( $old['sort']['sortDirection'] ?? '' ) == 'ASC' ? 'ASC' : 'DESC' ),
            ],
        ];
        if ( ($old['type'] ?? '') === 'File Browser' ) {
            $advanced['fileBrowser'] = [
                'listViewTableHead' => [
                    'enable'  => false,
                    'name'    => $old['fileBrowserGridTableHeadFields']['fileName'] ?? 'Name',
                    'type'    => $old['fileBrowserGridTableHeadFields']['fileType'] ?? 'Type',
                    'size'    => $old['fileBrowserGridTableHeadFields']['fileSize'] ?? 'Size',
                    'updated' => $old['fileBrowserGridTableHeadFields']['updatedAt'] ?? 'Updated',
                    'actions' => $old['fileBrowserGridTableHeadFields']['actions'] ?? 'Actions',
                ],
                'folderView'        => ( ($old['fileBrowserPreviewStyle'] ?? '') == 'List' ? 'list' : 'grid' ),
                'headerOptions'     => [
                    'status'      => ($old['browserHeader'] ?? '') == 'true',
                    'breadcrumb'  => ($old['breadCrumbsInHeader'] ?? '') == 'true',
                    'refresh'     => ($old['refreshButtonInHeader'] ?? '') == 'true',
                    'sorting'     => ($old['sortingButtonInHeader'] ?? '') == 'true',
                    'root_upload' => false,
                ],
            ];
        } elseif ( ($old['type'] ?? '') === 'File Uploader' ) {
            $advanced['fileUploader'] = [
                'folderUpload'           => ($old['enableFolderUpload'] ?? '') == 'true',
                'multipleUpload'         => ($old['allowMultipleUpload'] ?? '') == 'true',
                'uploadPreview'          => [
                    'enable'            => ($old['fileUploaderPreviewMode'] ?? '') == 'true',
                    'previewStyle'      => ( $old['fileBrowserPreviewStyle'] == 'List' ? 'list' : 'grid' ),
                    'showHeader'        => [
                        'enable'       => ($old['browserHeader'] ?? '') == 'true',
                        'breadcrumb'   => ($old['breadCrumbsInHeader'] ?? '') == 'true',
                        'searchBox'    => ($old['searchBoxInHeader'] ?? '') == 'true',
                        'createFolder' => ($old['creatFolderButtonInHeader'] ?? '') == 'true',
                        'sorting'      => ($old['sortingButtonInHeader'] ?? '') == 'true',
                    ],
                    'fileAction'        => [
                        'enable'   => ($old['fileBrowserFileActions'] ?? '') == 'true',
                        'preview'  => ($old['previewButtonInFileCard'] ?? '') == 'true',
                        'download' => ($old['downloadButtonInFileCard'] ?? '') == 'true',
                        'rename'   => false,
                        'delete'   => false,
                        'share'    => false,
                    ],
                    'listViewTableHead' => [
                        'enable'  => false,
                        'name'    => $old['fileBrowserGridTableHeadFields']['fileName'] ?? 'Name',
                        'type'    => $old['fileBrowserGridTableHeadFields']['fileType'] ?? 'Type',
                        'size'    => $old['fileBrowserGridTableHeadFields']['fileSize'] ?? 'Size',
                        'updated' => $old['fileBrowserGridTableHeadFields']['updatedAt'] ?? 'Updated',
                        'actions' => $old['fileBrowserGridTableHeadFields']['actions'] ?? 'Actions',
                    ],
                ],
                'showBoxLabel'           => ($old['showUploadLabel'] ?? '') == 'true',
                'labelText'              => $old['uploadLabelText'] ?? "Upload Files",
                'renameFile'             => $this->convert_template_placeholders( $old['fileRenameTemplate'] ?? '' ),
                'uploadImmediately'      => ($old['uploadImmediately'] ?? '') == 'true',
                'showUploadConfirmation' => true,
                'confirmationMessage'    => "<h3>Upload successful!</h3><p>Your file(s) have been uploaded. Thank you for your submission!</p>",
            ];
        } elseif ( ($old['type'] ?? '') === 'Media Player' ) {
            $advanced['mediaPlayer'] = [
                "showNextPrevious"    => ($old['showNextPrevious'] ?? '') == 'true',
                "showAndHidePlaylist" => ($old['showHidePlayList'] ?? '') == 'true',
                "openedPlaylist"      => ($old['openedPlaylist'] ?? '') == 'true',
                "showNumberPrefix"    => ($old['showNumberPrefixInPlaylist'] ?? '') == 'true',
                "showThumbnail"       => ($old['showThumbnailInPlaylist'] ?? '') == 'true',
                "playlistTitle"       => "All Content",
                "playlistPosition"    => strtolower( $old['playlistPosition'] ?? 'right' ),
                "playlistLayout"      => "list",
                "columns"             => 1,
                "videoRatio"          => "16/9",
                "backgroundColor"     => $old['mediaPlayerBackgroundColor'] ?? "#ffffff",
                "textColor"           => $old['mediaPlayerTextColor'] ?? "#000e25",
            ];
        } elseif ( ($old['type'] ?? '') === 'Gallery' ) {
            $advanced['gallery'] = [
                'layout'                    => strtolower( $old['layout'] ?? "grid" ),
                'rowHeight'                 => $old['rowHeight'] ?? 200,
                "columnsDevice"             => "desktop",
                'columns'                   => [
                    'desktop' => intval( $old['desktopcolumn'] ?? 4 ),
                    'tablet'  => intval( $old['tabletcolumn'] ?? 3 ),
                    'mobile'  => intval( $old['mobilecolumn'] ?? 2 ),
                ],
                'aspectRatio'               => $old['aspectRatio'] ?? '1:1',
                'thumbnailSpacing'          => intval( $old['imgmargin'] ?? 10 ),
                'thumbnailQuality'          => strtolower( $old['thumbnailQuality'] ?? "thumbnail" ),
                'thumbnailView'             => strtolower( $old['thumbnailView'] ?? "rounded" ),
                'showOverlay'               => true,
                'overlayDisplayType'        => 'hover',
                'overlayDisplayTitle'       => true,
                'overlayDisplayDescription' => false,
                'overlayDisplaySize'        => false,
            ];
        } elseif ( ($old['type'] ?? '') === 'Slider Carousel' ) {
            $sliderType = ( $old['sliderType'] === 'Normal Slider' ? 'horizontal' : 'centered' );
            $slideDotsNavigation = ($old['slideDotsNavigation'] ?? '') == 'true';
            $slideArrowsNavigation = ($old['slideArrowsNavigation'] ?? '') == 'true';
            $navigationStyle = 'none';
            if ( $slideDotsNavigation && $slideArrowsNavigation ) {
                $navigationStyle = 'arrows-dots';
            } elseif ( $slideDotsNavigation ) {
                $navigationStyle = 'dots';
            } elseif ( $slideArrowsNavigation ) {
                $navigationStyle = 'arrows';
            }
            $advanced['sliderCarousel'] = [
                "sliderType"         => $sliderType,
                "sliderEffect"       => "slide",
                "showNavigation"     => true,
                "navigationStyle"    => $navigationStyle,
                "slideToShowDisplay" => $old['slideScreenSize'] ?? "desktop",
                "slideToShow"        => [
                    "desktop" => intval( $old['desktop_sliderperpage'] ?? 4 ),
                    "tablet"  => intval( $old['tablet_sliderperpage'] ?? 3 ),
                    "mobile"  => intval( $old['mobile_sliderperpage'] ?? 1 ),
                ],
                "itemGap"            => intval( $old['slidegap'] ?? 10 ),
                "borderRadius"       => intval( $old['sliderounded'] ?? 0 ),
                "slideAutoPlay"      => ($old['SlideAutoplay'] ?? '') == 'true',
                "autoPlaySpeed"      => intval( $old['autoplaySpeed'] ?? 3000 ),
                "infiniteLoop"       => ($old['slideloop'] ?? '') == 'true',
                "mouseControl"       => false,
                "showSliderCaption"  => false,
                "sliderDirection"    => "horizontal",
            ];
        } elseif ( ($old['type'] ?? '') === 'Embed Documents' ) {
            $edWidth = $this->parseCssValue( $old['embedIframeWidth'] ?? '100%', '%' );
            $edHeight = $this->parseCssValue( $old['embedIframeHeight'] ?? '650px', 'px' );
            $advanced['embedDocuments'] = [
                "showFileName"       => ($old['showFileName'] ?? '') == 'true',
                "directMediaDisplay" => false,
                "width"              => [
                    "value" => $edWidth['value'],
                    "unit"  => $edWidth['unit'],
                ],
                "height"             => [
                    "value" => $edHeight['value'],
                    "unit"  => $edHeight['unit'],
                ],
                "allowPopOut"        => ($old['allowPopUp'] ?? '') == 'true',
            ];
        } elseif ( ($old['type'] ?? '') === 'Search Box' ) {
            $advanced['searchBox'] = [
                "browserView"      => "grid",
                "showLastModified" => false,
                "searchBoxText"    => "Search for files & content",
            ];
        } elseif ( ($old['type'] ?? '') === 'Download Links' || ($old['type'] ?? '') === 'View Links' ) {
            $advanced['fileList'] = [
                "viewButtonText"          => $old['viewBtnText'] ?? "View",
                "viewBackgroundColor"     => $old['viewButtonBackgroundColor'] ?? "#0061fe",
                "viewTextColor"           => $old['viewButtonTextColor'] ?? "#ffffff",
                "viewBorderRadius"        => intval( $old['viewbuttonborderradius'] ?? 10 ),
                "viewButtonSize"          => "medium",
                "downloadButton"          => ($old['type'] ?? '') === 'Download Links' || ($old['showViewButtonOnDownloadCard'] ?? '') == 'true',
                "downloadButtonText"      => $old['downloadBtnText'] ?? "Download",
                "downloadBackgroundColor" => $old['downloadButtonBackgroundColor'] ?? "#0061fe",
                "downloadTextColor"       => $old['downloadButtonTextColor'] ?? "#ffffff",
                "downloadBorderRadius"    => intval( $old['downloadbuttonborderradius'] ?? 10 ),
                "downloadButtonSize"      => "medium",
                "columnsDevice"           => "desktop",
                "columns"                 => [
                    'desktop' => intval( $old['desktop_download_card_per_row'] ?? 4 ),
                    'tablet'  => intval( $old['tablet_download_card_per_row'] ?? 2 ),
                    'mobile'  => intval( $old['mobile_download_card_per_row'] ?? 1 ),
                ],
                "openInNewTab"            => false,
                "showFileSize"            => true,
                "showFileClicks"          => true,
                "showTimeStamp"           => true,
            ];
        }
        return [
            'source'        => $source,
            'filter'        => $filter,
            'notifications' => $notifications,
            'permissions'   => $permissions,
            'advanced'      => $advanced,
        ];
    }

    public function migration_user_access_data() {
        $dependentMigration = self::MIGRATION_KEYS['user_access_table'];
        if ( !get_option( $dependentMigration ) ) {
            $res = $this->migrate_user_access_table();
            if ( $res === false ) {
                return false;
            }
        }
        global $wpdb;
        $oldTable = "{$wpdb->prefix}indbox_user_access";
        $newTable = "{$wpdb->prefix}ccpidb_user_access";
        $migration_key = self::MIGRATION_KEYS['user_access_data'];
        if ( get_option( $migration_key ) ) {
            return true;
        }
        try {
            $user_access_data = $wpdb->get_results( $wpdb->prepare( "SELECT id, folders FROM %i", $oldTable ), ARRAY_A );
            if ( empty( $user_access_data ) ) {
                update_option( $migration_key, true );
                return true;
            }
            foreach ( $user_access_data as $row ) {
                try {
                    $folders = maybe_unserialize( $row['folders'] );
                    $new_folders = ( is_array( $folders ) ? array_map( function ( $folder ) {
                        if ( !isset( $folder['file_id'] ) || !isset( $folder['account_id'] ) ) {
                            return null;
                        }
                        return ccpidbGenerateKey( $folder['file_id'], $folder['account_id'] );
                    }, $folders ) : [] );
                    $new_folders = array_filter( $new_folders );
                    $new_pages = [
                        'file_browser',
                        'module_builder',
                        'settings',
                        'media_library'
                    ];
                    $result = $wpdb->update(
                        $newTable,
                        [
                            'folders' => serialize( $new_folders ),
                            'pages'   => serialize( $new_pages ),
                        ],
                        [
                            'id' => $row['id'],
                        ],
                        ['%s', '%s'],
                        ['%d']
                    );
                } catch ( Exception $e ) {
                    continue;
                }
            }
            update_option( $migration_key, true );
            return true;
        } catch ( Exception $e ) {
            update_option( $migration_key, false );
            return false;
        }
    }

    public function setRewriteRules() {
        $migration_key = self::MIGRATION_KEYS['rewrite_rules'];
        if ( get_option( $migration_key ) ) {
            return;
        }
        if ( function_exists( 'add_rewrite_rule' ) === false || function_exists( 'add_rule' ) === false ) {
            return;
        }
        add_rewrite_rule( '^ccpidb/([^/]+)/([^/]+)/([^/]+)\\.([^/]+)$', 'index.php?ccpidb-action=$matches[1]&ccpidb-key=$matches[2]&ccpidb-name=$matches[3]&ccpidb-ext=$matches[4]', 'top' );
        flush_rewrite_rules();
        update_option( $migration_key, true );
    }

    public function mediaLibraryMigration() {
        $migration_key = self::MIGRATION_KEYS['media_library'];
        if ( get_option( $migration_key ) ) {
            return true;
        }
        update_option( $migration_key, true );
        return true;
        $paged = 1;
        $perPage = 20;
        if ( class_exists( '\\WP_Query' ) === false ) {
            return false;
        }
        do {
            $query = new WP_Query([
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => $perPage,
                'paged'          => $paged,
                'meta_query'     => [[
                    'key'     => '_indbox_media_folder_id',
                    'compare' => 'EXISTS',
                ], [
                    'key'     => '_ccpidb_media_folder_path',
                    'compare' => 'NOT EXISTS',
                ]],
                'fields'         => 'ids',
            ]);
            if ( !$query->have_posts() ) {
                update_option( $migration_key, true );
                break;
            }
            foreach ( $query->posts as $attachmentId ) {
                try {
                    $folderId = get_post_meta( $attachmentId, '_indbox_media_folder_id', true );
                    $accountId = get_post_meta( $attachmentId, '_indbox_media_account_id', true );
                    $fileId = get_post_meta( $attachmentId, '_indbox_media_file_id', true );
                    if ( !$folderId || !$accountId || !$fileId ) {
                        continue;
                    }
                    $fileKey = ccpidbGenerateKey( $fileId, $accountId );
                    $file = App::getInstance()->getFile( $fileKey );
                    if ( !$file instanceof File ) {
                        continue;
                    }
                    $extension = $file->getExtension();
                    $fileName = $file->getName();
                    $fullUrl = ccpidbGetUrl(
                        'attachment',
                        $fileKey,
                        $fileName,
                        MediaLibrary__premium_only::FULL,
                        $extension
                    );
                    $thumbnailUrl = ccpidbGetUrl(
                        'attachment',
                        $fileKey,
                        $fileName,
                        MediaLibrary__premium_only::THUMBNAIL,
                        $extension
                    );
                    $mediumUrl = ccpidbGetUrl(
                        'attachment',
                        $fileKey,
                        $fileName,
                        MediaLibrary__premium_only::MEDIUM,
                        $extension
                    );
                    $largeUrl = ccpidbGetUrl(
                        'attachment',
                        $fileKey,
                        $fileName,
                        MediaLibrary__premium_only::LARGE,
                        $extension
                    );
                    wp_update_post( [
                        'ID'   => $attachmentId,
                        'guid' => esc_url_raw( $fullUrl ),
                    ] );
                    $meta = [
                        'width'        => 150,
                        'height'       => 150,
                        'ccpidb_media' => true,
                        'key'          => $fileKey,
                        'extension'    => $extension,
                        'file'         => basename( $fullUrl ),
                        'thumbnail'    => $fullUrl,
                        'name'         => $fileName,
                    ];
                    $mediaInfo = $file->getMetaData( 'mediaInfo' );
                    if ( is_array( $mediaInfo ) ) {
                        $meta['width'] = ( isset( $mediaInfo['width'] ) ? (int) $mediaInfo['width'] : $meta['width'] );
                        $meta['height'] = ( isset( $mediaInfo['height'] ) ? (int) $mediaInfo['height'] : $meta['height'] );
                    }
                    $meta['sizes'] = [
                        'full'      => [
                            'url'    => $fullUrl,
                            'width'  => $meta['width'],
                            'height' => $meta['height'],
                            'file'   => basename( $fullUrl ),
                        ],
                        'large'     => [
                            'url'    => $largeUrl,
                            'width'  => 1024,
                            'height' => 768,
                            'file'   => basename( $largeUrl ),
                        ],
                        'medium'    => [
                            'url'    => $mediumUrl,
                            'width'  => 480,
                            'height' => 320,
                            'file'   => basename( $mediumUrl ),
                        ],
                        'thumbnail' => [
                            'url'    => $thumbnailUrl,
                            'width'  => 128,
                            'height' => 128,
                            'file'   => basename( $thumbnailUrl ),
                        ],
                    ];
                    update_post_meta( $attachmentId, '_ccpidb_media_folder_path', $file->getParent() );
                    update_post_meta( $attachmentId, '_ccpidb_media_file_key', $fileKey );
                    update_post_meta( $attachmentId, '_ccpidb_media_account_id', $accountId );
                    update_post_meta( $attachmentId, '_wp_attached_file', basename( $fullUrl ) );
                    update_post_meta( $attachmentId, '_wp_attachment_metadata', $meta );
                    update_post_meta( $attachmentId, '_ccpidb_media_migrated', 1 );
                    Files::getInstance()->updateMetaData( $file->getPath(), $file->getAccountId(), [
                        'attachmentId' => $attachmentId,
                    ] );
                } catch ( Exception $e ) {
                    Notices::getInstance()->add( [
                        'type'    => 'error',
                        'message' => sprintf( 'Media Library Migration Error: %s', $e->getMessage() ),
                    ] );
                }
            }
            wp_reset_postdata();
            $paged++;
        } while ( true );
        update_option( $migration_key, true );
        return true;
    }

    private function parseCssValue( $value, $defaultUnit = 'px' ) {
        $validUnits = [
            'px',
            '%',
            'em',
            'rem',
            'vw',
            'vh',
            'vmin',
            'vmax',
            'ch',
            'ex',
            'cm',
            'mm',
            'in',
            'pt',
            'pc'
        ];
        if ( $value === 'auto' ) {
            return [
                'value' => 0,
                'unit'  => 'auto',
            ];
        }
        if ( is_numeric( $value ) ) {
            return [
                'value' => (float) $value,
                'unit'  => $defaultUnit,
            ];
        }
        if ( is_string( $value ) && preg_match( '/^([\\d.]+)\\s*([a-z%]+)$/i', trim( $value ), $matches ) ) {
            $number = (float) $matches[1];
            $unit = strtolower( $matches[2] );
            if ( in_array( $unit, $validUnits, true ) ) {
                return [
                    'value' => $number,
                    'unit'  => $unit,
                ];
            }
        }
        return [
            'value' => 0,
            'unit'  => 'auto',
        ];
    }

    /**
     * Convert %placeholders% to {placeholders} with a dynamic separator.
     *
     * @param string $template Original template string
     * @param string $separator Separator between placeholders (-, _, space, ;)
     *
     * @return string
     */
    public function convert_template_placeholders( string $template, string $separator = '-' ) : string {
        $allowed_separators = [
            '-',
            '_',
            ' ',
            ';'
        ];
        if ( !in_array( $separator, $allowed_separators, true ) ) {
            return $template;
        }
        $template = preg_replace( '/%([a-zA-Z0-9_]+)%/', '{$1}', $template );
        $template = preg_replace( '/\\}\\s*[-_ ;]*\\s*\\{/', '}' . $separator . '{', $template );
        return $template;
    }

}
