<?php

namespace CodeConfig\IDB\Models;

defined( 'ABSPATH' ) || exit( 'No direct script access allowed' );
use CodeConfig\IDB\App\Account;
use CodeConfig\IDB\App\Accounts;
use CodeConfig\IDB\App\File;
use function CodeConfig\IDB\ccpidb_fs;
use CodeConfig\IDB\Utils\Helpers;
use CodeConfig\IDB\Utils\MimeTypeManager;
use CodeConfig\IDB\Utils\Singleton;
use function count;
use function in_array;
use function intval;
use function is_array;
use WP_Error;
// phpcs:disable WordPress.DB.DirectDatabaseQuery
class Files extends BaseModel {
    use Singleton;
    public const TABLE_NAME = 'ccpidb_files';

    public const COLUMNS = [
        'id',
        'fileId',
        'fileKey',
        'path',
        'name',
        'size',
        'parent',
        'accountId',
        'mimeType',
        'extension',
        'thumbnail',
        'thumbnailRatio',
        'description',
        'sharedLink',
        'isDir',
        'permissions',
        'hasOwnThumbnail',
        'icon',
        'additionalData',
        'createdAt',
        'updatedAt'
    ];

    public const ADDITIONAL_DATA = [
        'tag',
        'rev',
        'path_display',
        'clientModified',
        'serverModified',
        'hasExplicitSharedMembers',
        'basename',
        'mediaInfo',
        'sharingInfo'
    ];

    public const META_DATA = ['attachmentId', 'mediaInfo', 'childCount'];

    public function __construct() {
        parent::__construct( self::TABLE_NAME );
    }

    /**
     * Retrieves a list of files from the specified folder and account.
     *
     * @param string $folderKey The key of the folder to retrieve files from.
     * @param array $config Optional configuration settings for retrieving files.
     *
     * @return array|null|WP_Error An array of processed file data from the specified folder.
     */
    public function getFolder( $folderKey, $config = [] ) {
        global $wpdb;
        $allowedOrderBy = [
            'createdAt',
            'name',
            'updatedAt',
            'size'
        ];
        $order = $this->sanitizeOrder( $config['order'] ?? 'DESC' );
        $orderBy = $this->sanitizeOrderBy( $config['orderBy'] ?? 'createdAt', $allowedOrderBy );
        $page = ( isset( $config['page'] ) ? (int) $config['page'] : 1 );
        $perPage = ( isset( $config['perPage'] ) ? (int) $config['perPage'] : self::DEFAULT_ITEMS_PER_PAGE );
        $pagination = $this->sanitizePagination( $page, $perPage );
        if ( $folderKey !== '/' ) {
            $file = $this->getFile( $folderKey );
            if ( $file instanceof File === false || is_wp_error( $file ) ) {
                return new WP_Error(404, __( 'Folder not found.', 'integrate-dropbox' ));
            }
            $accountId = $file->getAccountId();
            $path = $file->getPath();
        } else {
            $account = Accounts::getInstance()->getAccount();
            if ( $account instanceof Account === false || is_wp_error( $account ) ) {
                return new WP_Error(404, __( 'Account not found.', 'integrate-dropbox' ));
            }
            $accountId = $account->getId();
            $userAccess = ccpidbGetCurrentUserAccess();
            if ( !empty( $userAccess['folders'] ) && is_array( $userAccess['folders'] ) ) {
                $allowedFolders = $userAccess['folders'];
                $files = $this->getFilesByKeys( $allowedFolders, [
                    'returnType' => 'array',
                    'perPage'    => $pagination['perPage'],
                    'page'       => $pagination['page'],
                    'orderBy'    => $orderBy,
                    'order'      => $order,
                    'recursive'  => false,
                ] );
                return $files;
            }
            $path = '/';
        }
        if ( !current_user_can( 'manage_options' ) && !wp_doing_cron() ) {
            if ( !is_user_logged_in() ) {
                return new WP_Error(401, __( 'You must be logged in to access this folder.', 'integrate-dropbox' ));
            }
            $user = wp_get_current_user();
            if ( !$user instanceof \WP_User ) {
                return new WP_Error(401, __( 'You must be logged in to access this folder.', 'integrate-dropbox' ));
            }
            $userName = $user->user_login;
            $roles = $user->roles;
            $accessSettings = UserAccess::getInstance()->getAccessData( $userName, $roles );
            if ( empty( $accessSettings ) ) {
                if ( !current_user_can( 'manage_options' ) ) {
                    return new WP_Error(403, __( 'You do not have permission to access this folder.', 'integrate-dropbox' ));
                }
            } else {
                $accessSettingsFolders = $accessSettings['folders'] ?? [];
                if ( empty( $accessSettingsFolders ) || !is_array( $accessSettingsFolders ) ) {
                    return new WP_Error(403, __( 'You do not have permission to access this folder.', 'integrate-dropbox' ));
                }
                if ( $folderKey === '/' ) {
                    $folder = $this->getFilesByKeys( $accessSettingsFolders, [
                        'returnType' => 'array',
                        'perPage'    => $config['perPage'],
                        'page'       => $config['page'],
                        'orderBy'    => $config['orderBy'],
                        'order'      => $config['order'],
                        'recursive'  => false,
                    ] );
                    return $folder;
                }
                if ( !Helpers::validateFileKey( $folderKey, $accessSettingsFolders ) ) {
                    return new WP_Error(403, __( 'You do not have permission to access this folder.', 'integrate-dropbox' ));
                }
            }
        }
        $sql = $wpdb->prepare( "SELECT * FROM %i WHERE accountId = %s", $this->tableName, $accountId );
        $totalSql = $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE accountId = %s", $this->tableName, $accountId );
        if ( !empty( $config['search'] ) ) {
            $searchPattern = '%' . $wpdb->esc_like( $config['search'] ?? '' ) . '%';
            $searchScope = ( in_array( $config['searchScope'] ?? 'folder', ['folder', 'global'] ) ? $config['searchScope'] : 'folder' );
            $sql .= $wpdb->prepare( " AND name LIKE %s", $searchPattern );
            $totalSql .= $wpdb->prepare( " AND name LIKE %s", $searchPattern );
            if ( $searchScope === 'folder' ) {
                $sql .= $wpdb->prepare( " AND (path LIKE %s OR parent LIKE %s)", "{$path}%", "{$path}%" );
                $totalSql .= $wpdb->prepare( " AND (path LIKE %s OR parent LIKE %s)", "{$path}%", "{$path}%" );
            }
        } else {
            if ( !empty( $config['recursive'] ) && $config['recursive'] === true ) {
                $sql .= $wpdb->prepare( " AND parent LIKE %s", "{$path}%" );
                $totalSql .= $wpdb->prepare( " AND parent LIKE %s", "{$path}%" );
            } else {
                $sql .= $wpdb->prepare( " AND parent = %s", $path );
                $totalSql .= $wpdb->prepare( " AND parent = %s", $path );
            }
        }
        if ( !empty( $config['types'] ) && is_array( $config['types'] ) ) {
            $types = $config['types'];
            $extensions = MimeTypeManager::getExtensionsByCategory( $types );
            $placeholders = implode( ',', array_fill( 0, count( $extensions ), '%s' ) );
            //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            $sql .= $wpdb->prepare( " AND `extension` IN ({$placeholders})", $extensions );
            //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            $totalSql .= $wpdb->prepare( " AND `extension` IN ({$placeholders})", $extensions );
        }
        if ( $order === 'ASC' ) {
            $sql .= $wpdb->prepare(
                " ORDER BY (CASE WHEN extension = 'folder' THEN 0 ELSE 1 END), %i ASC LIMIT %d OFFSET %d",
                $orderBy,
                $pagination['perPage'],
                $pagination['offset']
            );
        } else {
            $sql .= $wpdb->prepare(
                " ORDER BY (CASE WHEN extension = 'folder' THEN 0 ELSE 1 END), %i DESC LIMIT %d OFFSET %d",
                $orderBy,
                $pagination['perPage'],
                $pagination['offset']
            );
        }
        $cache_key = "ccpidb_folder_" . md5( $sql );
        $cache_group = "ccpidb_files_" . md5( "{$path}_{$accountId}" );
        $files = wp_cache_get( $cache_key, $cache_group );
        if ( $files !== false ) {
            return $files;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $files = $wpdb->get_results( $sql );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $total = $wpdb->get_var( $totalSql );
        if ( empty( $files ) || is_wp_error( $files ) ) {
            $files = [];
        }
        $processedFiles = $this->processFiles( $files );
        $totalPages = ceil( $total / $perPage );
        $hasMore = $totalPages > $page;
        $response = [
            'breadcrumb'  => array_reverse( $this->getBreadcrumbByKey( $folderKey ) ),
            'totalPage'   => ( $totalPages < 1 ? 1 : $totalPages ),
            'hasMore'     => $hasMore,
            'currentPage' => $page,
            'files'       => $processedFiles,
            'totalFiles'  => intval( $total ),
        ];
        if ( $hasMore ) {
            $response['nextPage'] = $page + 1;
        }
        if ( !empty( $processedFiles ) ) {
            wp_cache_set(
                $cache_key,
                $response,
                $cache_group,
                MINUTE_IN_SECONDS * 5
            );
        }
        return $response;
    }

    /**
     * Retrieves a file by its ID and account ID.
     *
     * This method queries the database for a file associated with the given
     * ID and account ID. If a matching file is found, it processes and returns
     * the file data. If no file is found, an error notice is added and null is
     * returned.
     *
     * @param string $fileKey The key of the file to retrieve.
     * @param string $returnType The type of return value, either 'object' or 'array'.
     *
     * @return \CodeConfig\IDB\App\File|array|false The processed file data if found, otherwise null.
     */
    public function getFile( $fileKey, $returnType = 'object' ) {
        global $wpdb;
        // if ($this->isValidAccount($accountId) === false) {
        //     return new WP_Error(403, __('This account is lost or does not exist. Please re-authorize it.', 'integrate-dropbox'));
        // }
        // $file = $this->fetch("SELECT * FROM {$this->tableName} WHERE id = %s AND accountId = %s", [$id, $accountId]);
        $cache_key = "ccpidb_file_{$fileKey}_{$returnType}";
        $file = wp_cache_get( $cache_key, 'ccpidb_files' );
        if ( $file !== false ) {
            return $file;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $file = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE fileKey = %s", $this->tableName, $fileKey ) );
        if ( empty( $file ) || is_wp_error( $file ) ) {
            return false;
        }
        $processedFile = $this->processFile( $file, $returnType );
        if ( !empty( $processedFile ) ) {
            wp_cache_set( $cache_key, $processedFile, 'ccpidb_files' );
        }
        return $processedFile;
    }

    private function getFileByPath( $path, $accountId, $returnType = 'object' ) {
        global $wpdb;
        $cache_key = "ccpidb_file_{$path}_{$accountId}";
        $file = wp_cache_get( $cache_key, 'ccpidb_files' );
        if ( $file !== false ) {
            return $file;
        }
        $file = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM %i WHERE path = %s AND accountId = %s",
            $this->tableName,
            $path,
            $accountId
        ) );
        $processedFile = $this->processFile( $file, $returnType );
        if ( !empty( $processedFile ) ) {
            wp_cache_set( $cache_key, $processedFile, 'ccpidb_files' );
        }
        return $processedFile;
    }

    public function getFilesByKeys( array $keys, array $args = [] ) {
        if ( empty( $keys ) ) {
            return [];
        }
        $defaults = [
            'recursive'      => false,
            'returnType'     => 'array',
            'page'           => 1,
            'perPage'        => self::DEFAULT_ITEMS_PER_PAGE,
            'orderBy'        => 'name',
            'order'          => 'ASC',
            'search'         => '',
            'searchScope'    => 'folder',
            'searchLocation' => 'cache',
            'types'          => [],
            'accountId'      => '',
        ];
        $args = wp_parse_args( $args, $defaults );
        $page = (int) ($args['page'] ?? 1);
        $perPage = (int) ($args['perPage'] ?? self::DEFAULT_ITEMS_PER_PAGE);
        $orderBy = $args['orderBy'];
        $order = $args['order'];
        $recursive = $args['recursive'];
        $moduleType = $args['moduleType'] ?? '';
        $returnType = $args['returnType'];
        $shortcodeId = $args['shortcodeId'] ?? '';
        $additionalExtensions = $args['extensions'] ?? [];
        $extensionsFilterType = $args['extensionsFilterType'] ?? '';
        $search = $args['search'];
        $searchScope = $args['searchScope'];
        $namesString = $args['names'] ?? '';
        $namesFilterType = $args['namesFilterType'] ?? '';
        $applyNamesFilter = $args['applyNameFilter'] ?? [];
        $types = $args['types'] ?? '';
        if ( 'search-box' === $moduleType && empty( $search ) && $recursive && ($args['fileKey'] ?? '') !== '/' ) {
            $moduleType = 'file-browser';
        }
        $extensions = ccpidbGetAllowedModuleExtensions( $moduleType );
        $allowedExtensions = $this->processExtensions( $extensions, $additionalExtensions, $extensionsFilterType );
        $cacheKeyBase = 'ccpidb_keys_' . md5( serialize( $keys ) . serialize( $args ) );
        $cache_key = $cacheKeyBase . '_files';
        $cached = wp_cache_get( $cache_key, 'ccpidb_files' );
        if ( $cached !== false ) {
            return $cached;
        }
        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
        $preparedQuery = $wpdb->prepare( "SELECT path, accountId FROM %i WHERE `fileKey` IN ({$placeholders})", array_merge( [$this->tableName], $keys ) );
        $filesData = $wpdb->get_results( $preparedQuery, ARRAY_A );
        if ( empty( $filesData ) ) {
            return [];
        }
        $pathsWithAccount = [];
        foreach ( $filesData as $file ) {
            if ( !empty( $file['path'] ) && !empty( $file['accountId'] ) ) {
                if ( !isset( $pathsWithAccount[$file['accountId']] ) ) {
                    $pathsWithAccount[$file['accountId']] = [];
                }
                $pathsWithAccount[$file['accountId']][] = $file['path'];
            }
        }
        if ( empty( $pathsWithAccount ) ) {
            return [];
        }
        $sql = $wpdb->prepare( "SELECT * FROM %i WHERE 1=1", $this->tableName );
        $totalSql = $wpdb->prepare( "SELECT COUNT(*) as count FROM %i WHERE 1=1", $this->tableName );
        if ( !empty( $search ) ) {
            if ( $searchScope === 'global' ) {
                $allPaths = [];
                foreach ( $pathsWithAccount as $accId => $paths ) {
                    $allPaths[$accId] = $this->getAllDescendantPaths( $paths, $accId );
                }
                $pathsWithAccount = $allPaths;
            }
            if ( empty( $pathsWithAccount ) ) {
                return [];
            }
            $sqlLogic = [];
            foreach ( $pathsWithAccount as $accountId => $paths ) {
                $placeholders = implode( ',', array_fill( 0, count( $paths ), '%s' ) );
                $isMultiplePaths = count( $paths ) > 1;
                if ( $isMultiplePaths || 'global' === $searchScope ) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $sqlLogic[] = $wpdb->prepare( "((`path` IN ({$placeholders}) OR `parent` IN ({$placeholders})) AND `name` LIKE %s AND `accountId` = %s)", array_merge( $paths, $paths, ["%{$search}%", $accountId] ) );
                } else {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $sqlLogic[] = $wpdb->prepare( " (`parent` IN ({$placeholders}) AND `name` LIKE %s AND `accountId` = %s)", array_merge( $paths, ["%{$search}%, {$accountId}"] ) );
                }
            }
            $sql .= ' AND (' . implode( ' OR ', $sqlLogic ) . ')';
            $totalSql .= ' AND (' . implode( ' OR ', $sqlLogic ) . ')';
        } elseif ( $recursive ) {
            $sqlLogic = [];
            foreach ( $pathsWithAccount as $accountId => $paths ) {
                $placeholders = implode( ',', array_fill( 0, count( $paths ), '%s' ) );
                $folderExpandable = $args['folderExpandable'] ?? false;
                if ( $moduleType === 'file-browser' || $folderExpandable ) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                    $sqlLogic[] = $wpdb->prepare( "(`parent` IN ({$placeholders}) AND `accountId` = %s)", array_merge( $paths, [$accountId] ) );
                } elseif ( $moduleType === 'file-uploader' ) {
                    $uploadKeys = json_decode( sanitize_text_field( wp_unslash( $_COOKIE["ccpidb_file_uploader_files_{$shortcodeId}"] ?? '' ) ), true );
                    if ( empty( $uploadKeys ) || !is_array( $uploadKeys ) ) {
                        return [];
                    }
                    $uploadKeysPlaceholders = implode( ',', array_fill( 0, count( $uploadKeys ), '%s' ) );
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                    $sqlLogic[] = $wpdb->prepare( "(`parent` IN ({$placeholders}) AND `fileKey` IN ({$uploadKeysPlaceholders}) AND `accountId` = %s)", array_merge( $paths, $uploadKeys, [$accountId] ) );
                } else {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                    $sqlLogic[] = $wpdb->prepare( "((`path` IN ({$placeholders}) OR `parent` IN ({$placeholders})) AND `accountId` = %s)", array_merge( $paths, $paths, [$accountId] ) );
                }
            }
            $sql .= ' AND (' . implode( ' OR ', $sqlLogic ) . ')';
            $totalSql .= ' AND (' . implode( ' OR ', $sqlLogic ) . ')';
        } else {
            if ( !empty( $allowedExtensions ) && !in_array( 'folder', $allowedExtensions ) ) {
                $allowedExtensions[] = 'folder';
            }
            $sqlLogic = [];
            foreach ( $pathsWithAccount as $accountId => $paths ) {
                $placeholders = implode( ',', array_fill( 0, count( $paths ), '%s' ) );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                $sqlLogic[] = $wpdb->prepare( " `path` IN ({$placeholders}) AND `accountId` = %s", array_merge( $paths, [$accountId] ) );
            }
            $sql .= ' AND (' . implode( ' OR ', $sqlLogic ) . ')';
            $totalSql .= ' AND (' . implode( ' OR ', $sqlLogic ) . ')';
        }
        if ( !empty( $types ) && is_array( $types ) ) {
            $extensions = MimeTypeManager::getExtensionsByCategory( $types );
            $allowedExtensions = array_merge( $allowedExtensions, $extensions );
        }
        if ( !empty( $allowedExtensions ) ) {
            $extPlaceholders = implode( ',', array_fill( 0, count( $allowedExtensions ), '%s' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            $sql .= $wpdb->prepare( " AND `extension` IN ({$extPlaceholders})", $allowedExtensions );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            $totalSql .= $wpdb->prepare( " AND `extension` IN ({$extPlaceholders})", $allowedExtensions );
        }
        if ( !empty( $args['orderBy'] ) && !empty( $args['order'] ) ) {
            $allowedOrderBy = [
                'id',
                'name',
                'size',
                'createdAt',
                'updatedAt'
            ];
            $orderBy = $this->sanitizeOrderBy( $args['orderBy'], $allowedOrderBy );
            $order = $this->sanitizeOrder( $args['order'] );
            if ( $order === 'ASC' ) {
                $sql .= $wpdb->prepare( " ORDER BY (CASE WHEN extension = 'folder' THEN 0 ELSE 1 END), %i ASC", $orderBy );
            } else {
                $sql .= $wpdb->prepare( " ORDER BY (CASE WHEN extension = 'folder' THEN 0 ELSE 1 END), %i DESC", $orderBy );
            }
        }
        if ( !empty( $args['perPage'] ) && !empty( $args['page'] ) ) {
            $offset = $this->sanitizePagination( $args['page'], $args['perPage'] );
            $sql .= $wpdb->prepare( " LIMIT %d OFFSET %d", $offset['perPage'], $offset['offset'] );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $files = $wpdb->get_results( $sql );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $totalCount = $wpdb->get_row( $totalSql );
        if ( empty( $files ) || is_wp_error( $files ) || is_wp_error( $totalCount ) ) {
            return [];
        }
        if ( !empty( $processedExtensions ) && !in_array( 'folder', $processedExtensions ) ) {
            $allowedExtensions = array_filter( $processedExtensions, function ( $ext ) {
                return $ext !== 'folder';
            } );
        }
        $files = $this->processFiles( $files, $returnType, [
            'extension' => $allowedExtensions,
        ] );
        $totalFiles = ( isset( $totalCount->count ) ? (int) $totalCount->count : count( $files ) );
        $page = $offset['page'] ?? 1;
        $totalPage = ceil( $totalFiles / ($offset['perPage'] ?? self::DEFAULT_ITEMS_PER_PAGE) );
        $response = [
            'breadcrumb'  => [[
                'fileKey' => '/',
                'name'    => __( 'Home', 'integrate-dropbox' ),
            ]],
            'totalPage'   => ( $totalPage < 1 ? 1 : $totalPage ),
            'hasMore'     => $totalPage > $page,
            'currentPage' => $page,
            'files'       => $files,
            'totalFiles'  => $totalFiles,
        ];
        if ( $response['hasMore'] ) {
            $response['nextPage'] = $page + 1;
        }
        if ( !empty( $files ) ) {
            wp_cache_set(
                $cache_key,
                $response,
                'ccpidb_files',
                MINUTE_IN_SECONDS * 5
            );
        }
        return $response;
    }

    public function getFolderTree( $accountId, ... $rootPaths ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $sql = $wpdb->prepare( "SELECT fileKey, name, parent, path FROM %i WHERE `extension` = 'folder' AND accountId = %s", $this->tableName, $accountId );
        if ( !empty( $rootPaths ) && !in_array( '/', $rootPaths, true ) ) {
            $sql .= $wpdb->prepare( " AND ( 0=%d ", 1 );
            foreach ( $rootPaths as $rootPath ) {
                $sql .= $wpdb->prepare( " OR path LIKE %s OR path LIKE %s", "{$rootPath}/%", "{$rootPath}" );
            }
            $sql .= $wpdb->prepare( " OR 0=%d ) ", 1 );
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $files = $wpdb->get_results( $sql, ARRAY_A );
        if ( empty( $files ) || is_wp_error( $files ) ) {
            return [];
        }
        return $this->processFolderTree( $files, $rootPaths );
    }

    public function addFile( array $data ) {
        global $wpdb;
        $file = [
            'fileId'          => $data['fileId'] ?? '',
            'fileKey'         => $data['fileKey'] ?? '',
            'path'            => $data['path'] ?? '',
            'name'            => $data['name'] ?? null,
            'size'            => intval( $data['size'] ?? 0 ),
            'parent'          => $data['parent'] ?? null,
            'accountId'       => $data['accountId'] ?? '',
            'mimeType'        => $data['mimeType'] ?? '',
            'extension'       => $data['extension'] ?? null,
            'thumbnail'       => $data['thumbnail'] ?? null,
            'description'     => $data['description'] ?? null,
            'sharedLink'      => $data['sharedLink'] ?? null,
            'isDir'           => $data['isDir'] ?? null,
            'permissions'     => maybe_serialize( $data['permissions'] ?? [] ),
            'hasOwnThumbnail' => $data['hasOwnThumbnail'] ?? null,
            'icon'            => $data['icon'] ?? null,
            'additionalData'  => maybe_serialize( $data['additionalData'] ?? [] ),
            'createdAt'       => current_time( 'mysql' ),
            'updatedAt'       => current_time( 'mysql' ),
        ];
        if ( empty( $file['fileId'] ) || empty( $file['accountId'] ) ) {
            return new WP_Error(404, __( 'Missing file ID or account ID.', 'integrate-dropbox' ));
        }
        $format = [
            '%s',
            // fileId
            '%s',
            // fileKey
            '%s',
            // path
            '%s',
            // name
            '%d',
            // size
            '%s',
            // parent
            '%s',
            // accountId
            '%s',
            // mimeType
            '%s',
            // extension
            '%s',
            // thumbnail
            '%s',
            // description
            '%s',
            // sharedLink
            '%d',
            // isDir
            '%s',
            // permissions
            '%d',
            // hasOwnThumbnail
            '%s',
            // icon
            '%s',
            // additionalData
            '%s',
            // createdAt
            '%s',
        ];
        if ( !empty( $data['thumbnailRatio'] ) ) {
            $file['thumbnailRatio'] = $data['thumbnailRatio'];
            $format[] = '%s';
            // thumbnailRatio
        }
        if ( $this->isCachedFile( $file["fileKey"] ) ) {
            unset($file["id"]);
            unset($file["fileId"]);
            unset($file["fileKey"]);
            unset($file["createdAt"]);
            $updateFormat = [
                '%s',
                // path
                '%s',
                // name
                '%d',
                // size
                '%s',
                // parent
                '%s',
                // accountId
                '%s',
                // mimeType
                '%s',
                // extension
                '%s',
                // thumbnail
                '%s',
                // description
                '%s',
                // sharedLink
                '%d',
                // isDir
                '%s',
                // permissions
                '%d',
                // hasOwnThumbnail
                '%s',
                // icon
                '%s',
                // additionalData
                '%s',
            ];
            if ( !empty( $data['thumbnailRatio'] ) ) {
                $updateFormat[] = '%s';
                // thumbnailRatio
            }
            return $wpdb->update(
                $this->tableName,
                $file,
                [
                    'fileKey' => $data['fileKey'],
                ],
                $updateFormat,
                ['%s']
            );
        }
        return $wpdb->insert( $this->tableName, $file, $format );
    }

    public function deleteFiles( $fileKeys ) : int {
        global $wpdb;
        if ( empty( $fileKeys ) ) {
            return 0;
        }
        $fileKeys = (array) $fileKeys;
        $placeholders = implode( ',', array_fill( 0, count( $fileKeys ), '%s' ) );
        $sql = $wpdb->prepare( "SELECT accountId, path, isDir FROM %i WHERE fileKey IN ({$placeholders})", array_merge( [$this->tableName], $fileKeys ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $files = $wpdb->get_results( $sql, ARRAY_A );
        if ( empty( $files ) ) {
            return 0;
        }
        $filePaths = [];
        $pathsByAccount = [];
        foreach ( $files as $file ) {
            if ( empty( $file['path'] ) || empty( $file['accountId'] ) ) {
                continue;
            }
            if ( !empty( $file['isDir'] ) ) {
                $pathsByAccount[$file['accountId']][] = $file['path'];
            } else {
                $filePaths[] = $file['path'];
            }
        }
        foreach ( $pathsByAccount as $accountId => $paths ) {
            $filePaths = array_merge( $filePaths, $this->getAllDescendantPaths( $paths, $accountId ) );
        }
        if ( empty( $filePaths ) ) {
            return 0;
        }
        $placeholders = implode( ',', array_fill( 0, count( $filePaths ), '%s' ) );
        $sql = $wpdb->prepare( 
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            "DELETE FROM %i WHERE path IN ({$placeholders}) OR parent IN ({$placeholders})",
            array_merge( [$this->tableName], $filePaths, $filePaths )
         );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->query( $sql );
    }

    public function deleteFilesByAccount( $accountId ) {
        if ( empty( $accountId ) ) {
            return 0;
        }
        return $this->deleteRecords( [
            'accountId' => $accountId,
        ] );
    }

    public function isCachedFile( $fileKey ) {
        global $wpdb;
        $folder = $wpdb->get_row( $wpdb->prepare( "SELECT fileKey FROM %i WHERE fileKey = %s", $this->tableName, $fileKey ) );
        return !empty( $folder );
    }

    public function getPathById( $fileId ) {
        global $wpdb;
        $file = $wpdb->get_row( $wpdb->prepare( "SELECT path FROM %i WHERE fileId = %s", $this->tableName, $fileId ) );
        if ( empty( $file ) || is_wp_error( $file ) ) {
            return false;
        }
        return $file->path;
    }

    public function getPathsByKeys( $fileKeys ) {
        global $wpdb;
        $isString = \is_string( $fileKeys );
        if ( $isString ) {
            $fileKeys = [$fileKeys];
        }
        $placeholders = implode( ',', array_fill( 0, count( $fileKeys ), '%s' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $preparedQuery = $wpdb->prepare( "SELECT path FROM %i WHERE fileKey IN ({$placeholders})", array_merge( [$this->tableName], $fileKeys ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $results = $wpdb->get_results( $preparedQuery );
        if ( $isString && \count( $results ) === 1 ) {
            return $results[0]->path;
        }
        $paths = [];
        foreach ( $results as $row ) {
            $paths[] = $row->path;
        }
        return $paths;
    }

    public function getValuesByKeys(
        array $columns,
        array $fileKeys,
        array $where = [],
        array $where_format = [],
        $returnType = OBJECT
    ) {
        global $wpdb;
        // Validate inputs
        if ( empty( $columns ) || empty( $fileKeys ) ) {
            return [];
        }
        // Filter and sanitize columns
        $sanitizedColumns = array_filter( $columns, fn( $col ) => in_array( $col, self::COLUMNS, true ) );
        if ( empty( $sanitizedColumns ) ) {
            return [];
        }
        // Validate WHERE clause columns
        if ( !empty( $where ) ) {
            foreach ( array_keys( $where ) as $whereColumn ) {
                if ( !in_array( $whereColumn, self::COLUMNS, true ) ) {
                    return [];
                }
            }
        }
        // Prepare column identifiers using %i placeholders for security
        $columnPlaceholders = implode( ',', array_fill( 0, count( $sanitizedColumns ), '%i' ) );
        $keyPlaceholders = implode( ',', array_fill( 0, count( $fileKeys ), '%s' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $sql = $wpdb->prepare( "SELECT {$columnPlaceholders} FROM %i WHERE fileKey IN ({$keyPlaceholders})", array_merge( $sanitizedColumns, [$this->tableName], $fileKeys ) );
        // Add additional WHERE conditions with proper format specifiers
        if ( !empty( $where ) ) {
            // $whereConditions = [];
            $whereIndex = 0;
            $sql .= $wpdb->prepare( " AND (0=%d ", 1 );
            foreach ( $where as $column => $value ) {
                // Use provided format or default to %s
                $format = ( !empty( $where_format[$whereIndex] ) ? $where_format[$whereIndex] : '%s' );
                $format = ( in_array( $format, [
                    '%s',
                    '%d',
                    '%i',
                    '%f'
                ], true ) ? $format : '%s' );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $sql .= $wpdb->prepare( " OR %i = {$format} ", $column, $value );
                $whereIndex++;
            }
            $sql .= $wpdb->prepare( " OR 0=%d ) ", 1 );
            // $sql .= " AND " . implode(' AND ', $whereConditions);
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $results = $wpdb->get_results( $sql, $returnType );
        if ( empty( $results ) || is_wp_error( $results ) ) {
            return [];
        }
        return $results;
    }

    public function getAccountIdByFileKey( $fileKey ) {
        global $wpdb;
        $file = $wpdb->get_row( $wpdb->prepare( "SELECT accountId FROM %i WHERE fileKey = %s", $this->tableName, $fileKey ) );
        if ( empty( $file ) || is_wp_error( $file ) ) {
            return false;
        }
        return $file->accountId;
    }

    public function updateThumbnail( $fileKey, $url ) {
        global $wpdb;
        return $wpdb->update(
            $this->tableName,
            [
                'thumbnail' => $url,
            ],
            [
                'fileKey' => $fileKey,
            ],
            "%s",
            '%s'
        );
    }

    public function updateFile(
        array $where,
        $data,
        $format = null,
        $where_format = '%s'
    ) {
        global $wpdb;
        return $wpdb->update(
            $this->tableName,
            $data,
            $where,
            $format,
            $where_format
        );
    }

    public function updateMetaData(
        $path,
        $accountId,
        array $metaData,
        $clean = false
    ) {
        if ( $path === '' || $path === '/' || empty( $accountId ) ) {
            return new WP_Error(400, __( 'Invalid path or account ID.', 'integrate-dropbox' ));
        }
        global $wpdb;
        if ( $clean === false ) {
            $file = $this->getFileByPath( $path, $accountId, 'array' );
            if ( is_wp_error( $file ) ) {
                return $file;
            }
            $existingData = $file['metaData'] ?? [];
            if ( is_array( $existingData ) ) {
                $metaData = array_merge( $existingData, $metaData );
            }
        }
        $filteredMetaData = [];
        foreach ( self::META_DATA as $key ) {
            if ( isset( $metaData[$key] ) ) {
                $filteredMetaData[$key] = $metaData[$key];
            }
        }
        return $wpdb->update(
            $this->tableName,
            [
                'metaData' => maybe_serialize( $filteredMetaData ),
            ],
            [
                'path'      => $path,
                'accountId' => $accountId,
            ],
            ['%s'],
            ['%s', '%s']
        );
    }

    public function getBreadcrumbByKey( $key, $args = [] ) {
        $defaults = [
            'rootFileKey'   => null,
            'rootFolderKey' => '/',
        ];
        $args = wp_parse_args( $args, $defaults );
        $rootFileKey = $args['rootFileKey'];
        $rootFolderKey = $args['rootFolderKey'];
        $home = [[
            'fileKey' => '/',
            'name'    => __( 'Home', 'integrate-dropbox' ),
        ]];
        // Empty or root key
        if ( empty( $key ) || $key === '/' || $key === $rootFolderKey ) {
            return $home;
        }
        // Resolve root folder key if rootFileKey is provided
        if ( $rootFileKey !== null && $rootFolderKey === '/' ) {
            $rootFile = $this->getFile( $rootFileKey, 'array' );
            if ( is_wp_error( $rootFile ) ) {
                return $home;
            }
            $parentPath = $rootFile['parent'] ?? null;
            if ( $parentPath ) {
                $rootFolder = $this->getFileByPath( $parentPath, $rootFile['accountId'] ?? '', 'array' );
                if ( !is_wp_error( $rootFolder ) ) {
                    $rootFolderKey = $rootFolder['fileKey'] ?? '/';
                }
            }
        }
        $file = $this->getFile( $key, 'array' );
        if ( is_wp_error( $file ) ) {
            return $home;
        }
        $fileId = $file['fileId'] ?? null;
        $accountId = $file['accountId'] ?? null;
        if ( !$fileId || !$accountId ) {
            return $home;
        }
        // Start breadcrumb with current item
        $breadcrumb = [[
            'fileKey' => $key,
            'name'    => $file['name'] ?? __( 'Unknown Folder', 'integrate-dropbox' ),
        ]];
        $parentPath = $file['parent'] ?? null;
        // Stop if no parent
        if ( empty( $parentPath ) ) {
            return $breadcrumb;
        }
        // Resolve parent folder key
        $parentFolderKey = '/';
        if ( $parentPath !== '/' ) {
            $parentFolder = $this->getFileByPath( $parentPath, $accountId, 'array' );
            if ( is_wp_error( $parentFolder ) ) {
                return $home;
            }
            $parentFolderKey = $parentFolder['fileKey'] ?? '/';
        }
        // Stop at root folder
        if ( $parentFolderKey === '/' || $parentFolderKey === $rootFolderKey ) {
            return array_merge( $breadcrumb, $home );
        }
        // Recursive parent breadcrumb
        $parentBreadcrumb = $this->getBreadcrumbByKey( $parentFolderKey, [
            'rootFolderKey' => $rootFolderKey,
        ] );
        if ( is_wp_error( $parentBreadcrumb ) ) {
            return $parentBreadcrumb;
        }
        return array_merge( $breadcrumb, $parentBreadcrumb );
    }

    public function getFileAttributesByKeys( array $keys, array $attributes = ['id'], $accountId = null ) {
        if ( empty( $keys ) ) {
            return [];
        }
        global $wpdb;
        $sanitizedAttributes = array_filter( $attributes, fn( $col ) => in_array( $col, self::COLUMNS, true ) );
        if ( empty( $sanitizedAttributes ) ) {
            return [];
        }
        $placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
        $selectColsPlaceholders = implode( ',', array_fill( 0, count( $sanitizedAttributes ), '%i' ) );
        $cacheKey = "ccpidb_attrs_" . md5( implode( ',', $sanitizedAttributes ) . implode( ',', $keys ) . $accountId );
        if ( false !== ($cached = wp_cache_get( $cacheKey, 'ccpidb_files' )) ) {
            return $cached;
        }
        $sql = $wpdb->prepare( "SELECT {$selectColsPlaceholders} FROM %i WHERE fileKey IN ({$placeholders})", array_merge( $sanitizedAttributes, [$this->tableName], $keys ) );
        if ( !empty( $accountId ) ) {
            $sql .= $wpdb->prepare( " AND accountId = %s", $accountId );
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $files = $wpdb->get_results( $sql, ARRAY_A );
        if ( empty( $files ) ) {
            return [];
        }
        if ( count( $sanitizedAttributes ) === 1 ) {
            $attr = $sanitizedAttributes[0];
            $result = array_column( $files, $attr );
            wp_cache_set(
                $cacheKey,
                $result,
                'ccpidb_files',
                MINUTE_IN_SECONDS * 5
            );
            return $result;
        }
        wp_cache_set(
            $cacheKey,
            $files,
            'ccpidb_files',
            MINUTE_IN_SECONDS * 5
        );
        return $files;
    }

    private function getAllDescendantPaths( array $paths, $accountId ) {
        if ( empty( $paths ) ) {
            return [];
        }
        global $wpdb;
        $paths = array_unique( $paths );
        $allPaths = $paths;
        $queue = $paths;
        while ( !empty( $queue ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $queue ), '%s' ) );
            $query = $wpdb->prepare( "SELECT path FROM %i WHERE parent IN ({$placeholders}) AND accountId = %s AND extension = 'folder'", array_merge( [$this->tableName], $queue, [$accountId] ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $children = $wpdb->get_results( $query, ARRAY_A );
            if ( empty( $children ) ) {
                break;
            }
            $queue = [];
            foreach ( $children as $child ) {
                $childPath = $child['path'];
                if ( !in_array( $childPath, $allPaths, true ) ) {
                    $allPaths[] = $childPath;
                    $queue[] = $childPath;
                }
            }
        }
        return $allPaths;
    }

    public function getAllPhotos( $args = [] ) {
        $defaults = [
            'perPage' => 40,
            'page'    => 1,
            'orderBy' => 'name',
            'order'   => 'desc',
        ];
        $args = wp_parse_args( $args, $defaults );
        $perPage = (int) $args['perPage'];
        $page = (int) $args['page'];
        $orderBy = $this->sanitizeOrderBy( $args['orderBy'], [
            'name',
            'createdAt',
            'updatedAt',
            'size'
        ] );
        $order = $this->sanitizeOrder( $args['order'] );
        $offset = ($page - 1) * $perPage;
        global $wpdb;
        $cache_key = "ccpidb_all_photos_{$perPage}_{$page}_{$orderBy}_{$order}";
        $photos = wp_cache_get( $cache_key, 'ccpidb_files' );
        if ( !empty( $photos ) ) {
            return $photos;
        }
        $sql = $wpdb->prepare( "SELECT * FROM %i WHERE mimeType LIKE %s", $this->tableName, 'image/%' );
        if ( $order === 'ASC' ) {
            $sql .= $wpdb->prepare(
                " ORDER BY %i ASC LIMIT %d OFFSET %d",
                $orderBy,
                $perPage,
                $offset
            );
        } else {
            $sql .= $wpdb->prepare(
                " ORDER BY %i DESC LIMIT %d OFFSET %d",
                $orderBy,
                $perPage,
                $offset
            );
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $photos = $wpdb->get_results( $sql );
        if ( is_wp_error( $photos ) ) {
            return $photos;
        }
        $total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE mimeType LIKE %s", $this->tableName, 'image/%' ) );
        if ( is_wp_error( $total ) ) {
            return $total;
        }
        $totalPages = ceil( $total / $perPage );
        $hasMore = $page < $totalPages;
        $response = [
            'total'      => (int) $total,
            'perPage'    => $perPage,
            'page'       => $page,
            'totalPages' => (int) $totalPages,
            'hasMore'    => $hasMore,
            'photos'     => $this->processFiles( $photos ),
        ];
        if ( $hasMore ) {
            $response['nextPage'] = $page + 1;
        }
        if ( !empty( $photos ) ) {
            wp_cache_set( $cache_key, $response, 'ccpidb_files' );
        }
        return $response;
    }

    public function searchFiles( $query, $options = [] ) {
        global $wpdb;
        if ( empty( $query ) || empty( $options['accountId'] ) ) {
            return new WP_Error(401, __( 'Search query and account ID are required.', 'integrate-dropbox' ));
        }
        $defaults = [
            'path'    => '/',
            'scope'   => 'folder',
            'perPage' => self::DEFAULT_ITEMS_PER_PAGE,
            'page'    => 1,
            'orderBy' => 'name',
            'order'   => 'ASC',
        ];
        $options = wp_parse_args( $options, $defaults );
        $order = $this->sanitizeOrder( $options['order'] );
        $orderBy = $this->sanitizeOrderBy( $options['orderBy'], [
            'name',
            'createdAt',
            'updatedAt',
            'size'
        ] );
        $pagination = $this->sanitizePagination( $options['page'], $options['perPage'] );
        $perPage = $pagination['perPage'];
        $offset = $pagination['offset'];
        $page = $pagination['page'];
        $searchPattern = '%' . $wpdb->esc_like( $query ) . '%';
        $sql = $wpdb->prepare(
            "SELECT * FROM %i WHERE accountId = %s AND name LIKE %s",
            $this->tableName,
            $options['accountId'],
            $searchPattern
        );
        $totalSql = $wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE accountId = %s AND name LIKE %s",
            $this->tableName,
            $options['accountId'],
            $searchPattern
        );
        if ( $options['scope'] === 'folder' && !empty( $options['path'] ) ) {
            $sql .= $wpdb->prepare( " AND parent = %s", $options['path'] );
            $totalSql .= $wpdb->prepare( " AND parent = %s", $options['path'] );
        }
        if ( !empty( $options['types'] ) && is_array( $options['types'] ) ) {
            $extensions = MimeTypeManager::getExtensionsByCategory( $options['types'] );
            if ( !empty( $extensions ) ) {
                $extPlaceholders = implode( ',', array_fill( 0, count( $extensions ), '%s' ) );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                $sql .= $wpdb->prepare( " AND extension IN ({$extPlaceholders})", $extensions );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                $totalSql .= $wpdb->prepare( " AND extension IN ({$extPlaceholders})", $extensions );
            }
        }
        if ( $order === 'ASC' ) {
            $sql .= $wpdb->prepare(
                " ORDER BY %i ASC LIMIT %d OFFSET %d",
                $orderBy,
                $perPage,
                $offset
            );
        } else {
            $sql .= $wpdb->prepare(
                " ORDER BY %i DESC LIMIT %d OFFSET %d",
                $orderBy,
                $perPage,
                $offset
            );
        }
        $cache_key = "ccpidb_search_{$options['accountId']}_" . md5( $query . serialize( $options ) );
        $cached = wp_cache_get( $cache_key, 'ccpidb_files' );
        if ( !empty( $cached ) ) {
            return $cached;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $results = $wpdb->get_results( $sql );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $totalFile = $wpdb->get_var( $totalSql );
        if ( is_wp_error( $results ) || is_wp_error( $totalFile ) ) {
            return $results;
        }
        if ( empty( $results ) || $totalFile == 0 ) {
            return [];
        }
        $response = [
            'total'       => (int) $totalFile,
            'files'       => $this->processFiles( $results ),
            'perPage'     => $perPage,
            'totalPages'  => ceil( $totalFile / $perPage ),
            'currentPage' => $page,
            'hasMore'     => $page * $perPage < $totalFile,
        ];
        if ( $response['hasMore'] ) {
            $response['nextPage'] = $page + 1;
        }
        if ( !empty( $results ) ) {
            wp_cache_set( $cache_key, $response, 'ccpidb_files' );
        }
        return $response;
    }

    public function getSharedKey( $fileKey, $options = [] ) {
        $defaults = [
            'expireIn' => 3600,
            'password' => null,
        ];
        $options = wp_parse_args( $options, $defaults );
        $expireIn = intval( $options['expireIn'] );
        $password = sanitize_text_field( $options['password'] ?? null );
        $expiry = ( $expireIn > 0 ? time() + $expireIn : 0 );
        $passwordHash = ( !empty( $password ) ? md5( $password ) : '' );
        $sharedData = $this->getSharedData( $fileKey );
        $key = md5( "{$fileKey}|{$expiry}|{$passwordHash}" );
        if ( !empty( $sharedData[$key] ) && $sharedData[$key]['expiry'] >= time() ) {
            return "{$fileKey}-{$key}";
        }
        $sharedData[$key] = [
            'expiry'     => $expiry,
            'password'   => $passwordHash,
            'viewCount'  => 0,
            'lastViewed' => null,
        ];
        // Save entire sharedData list
        $this->saveSharedData( $fileKey, $sharedData );
        return "{$fileKey}-{$key}";
    }

    public function updateSharedKey( $fileKey, $shareLinkId, $options = [] ) {
        $sharedData = $this->getSharedData( $fileKey );
        if ( empty( $sharedData[$shareLinkId] ) ) {
            return new WP_Error(404, __( 'The specified share link does not exist.', 'integrate-dropbox' ));
        }
        $shareInfo = $sharedData[$shareLinkId];
        $expireIn = ( isset( $options['expireIn'] ) ? intval( $options['expireIn'] ) : null );
        $password = ( isset( $options['password'] ) ? sanitize_text_field( $options['password'] ) : null );
        if ( $expireIn !== null ) {
            $shareInfo['expiry'] = ( $expireIn > 0 ? time() + $expireIn : 0 );
        }
        if ( $password !== null ) {
            $shareInfo['password'] = ( !empty( $password ) ? md5( $password ) : '' );
        }
        // Update the shared data with the modified share info
        $sharedData[$shareLinkId] = $shareInfo;
        // Save the entire sharedData list
        return $this->saveSharedData( $fileKey, $sharedData );
    }

    public function deleteSharedKey( $fileKey, $shareLinkId ) {
        $sharedData = $this->getSharedData( $fileKey );
        if ( empty( $sharedData[$shareLinkId] ) && $shareLinkId !== 'all' ) {
            return false;
        }
        if ( $shareLinkId === 'all' ) {
            $sharedData = null;
        } else {
            unset($sharedData[$shareLinkId]);
        }
        return $this->saveSharedData( $fileKey, $sharedData );
    }

    public function getDownloadKey( $fileKey, $options = [] ) {
        $defaults = [
            'expireIn' => 3600,
            'password' => null,
            'limit'    => 0,
        ];
        $options = wp_parse_args( $options, $defaults );
        $expireIn = intval( $options['expireIn'] );
        $password = sanitize_text_field( $options['password'] ?? null );
        $limit = intval( $options['limit'] );
        $expiry = ( $expireIn > 0 ? time() + $expireIn : 0 );
        $passwordHash = ( !empty( $password ) ? md5( $password ) : '' );
        $downloadData = $this->getDownloadData( $fileKey );
        $key = md5( "{$fileKey}|{$expiry}|{$passwordHash}|{$limit}" );
        if ( !empty( $downloadData[$key] ) && $downloadData[$key]['expiry'] >= time() ) {
            return "{$fileKey}-{$key}";
        }
        $downloadData[$key] = [
            'expiry'     => $expiry,
            'password'   => $passwordHash,
            'limit'      => $limit,
            'viewCount'  => 0,
            'lastViewed' => null,
        ];
        // Save entire sharedData list
        $this->saveDownloadData( $fileKey, $downloadData );
        return "{$fileKey}-{$key}";
    }

    public function updateDownloadKey( $fileKey, $downloadLinkId, $options = [] ) {
        $downloadData = $this->getDownloadData( $fileKey );
        if ( empty( $downloadData[$downloadLinkId] ) ) {
            return new WP_Error(404, __( 'The specified download link does not exist.', 'integrate-dropbox' ));
        }
        $downloadInfo = $downloadData[$downloadLinkId];
        $expireIn = ( isset( $options['expireIn'] ) ? intval( $options['expireIn'] ) : null );
        $password = ( isset( $options['password'] ) ? sanitize_text_field( $options['password'] ) : null );
        $limit = ( isset( $options['limit'] ) ? intval( $options['limit'] ) : null );
        if ( $expireIn !== null ) {
            $downloadInfo['expiry'] = ( $expireIn > 0 ? time() + $expireIn : 0 );
        }
        if ( $password !== null ) {
            $downloadInfo['password'] = ( !empty( $password ) ? md5( $password ) : '' );
        }
        if ( $limit !== null ) {
            $downloadInfo['limit'] = max( 0, $limit );
        }
        // Update the shared data with the modified share info
        $downloadData[$downloadLinkId] = $downloadInfo;
        // Save the entire sharedData list
        return $this->saveDownloadData( $fileKey, $downloadData );
    }

    public function deleteDownloadKey( $fileKey, $downloadLinkId ) {
        $downloadData = $this->getDownloadData( $fileKey );
        if ( empty( $downloadData[$downloadLinkId] ) && $downloadLinkId !== 'all' ) {
            return false;
        }
        if ( $downloadLinkId === 'all' ) {
            $downloadData = null;
        } else {
            unset($downloadData[$downloadLinkId]);
        }
        return $this->saveDownloadData( $fileKey, $downloadData );
    }

    public function validateSharedLink( $combinedKey, $password = '' ) {
        [$fileKey, $linkKey] = $this->parseCombinedKey( $combinedKey );
        if ( !$fileKey || !$linkKey ) {
            return new WP_Error(400, __( 'The provided link is invalid.', 'integrate-dropbox' ));
        }
        $sharedData = $this->getSharedData( $fileKey );
        if ( empty( $sharedData[$linkKey] ) ) {
            return new WP_Error(404, __( 'The shared link does not exist.', 'integrate-dropbox' ));
        }
        $shareInfo = $sharedData[$linkKey];
        if ( $shareInfo['expiry'] < time() && $shareInfo['expiry'] != 0 ) {
            return new WP_Error(403, __( 'This shared link has expired.', 'integrate-dropbox' ));
        }
        $hashedPassword = md5( sanitize_text_field( $password ) );
        if ( !empty( $shareInfo['password'] ) ) {
            if ( empty( $password ) ) {
                return new WP_Error(401, __( 'This shared link is protected by a password. Please provide the password to access the file.', 'integrate-dropbox' ));
            }
            if ( $shareInfo['password'] !== $hashedPassword ) {
                return new WP_Error(401, __( 'The provided password is incorrect.', 'integrate-dropbox' ));
            }
        }
        $shareInfo['viewCount'] = intval( $shareInfo['viewCount'] ?? 0 ) + 1;
        $shareInfo['lastViewed'] = current_time( 'mysql' );
        $result = $this->updateSharedData( $combinedKey, $shareInfo );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return $sharedData[$linkKey];
    }

    public function validateDownloadLink( $combinedKey, $password = '' ) {
        [$fileKey, $linkKey] = $this->parseCombinedKey( $combinedKey );
        if ( !$fileKey || !$linkKey ) {
            return new WP_Error(400, __( 'The provided link is invalid.', 'integrate-dropbox' ));
        }
        $downloadData = $this->getDownloadData( $fileKey );
        if ( empty( $downloadData[$linkKey] ) ) {
            return new WP_Error(404, __( 'The download link does not exist.', 'integrate-dropbox' ));
        }
        $downloadInfo = $downloadData[$linkKey];
        if ( $downloadInfo['expiry'] < time() && $downloadInfo['expiry'] != 0 ) {
            $this->deleteDownloadEntry( $fileKey, $linkKey );
            return new WP_Error(403, __( 'This download link has expired.', 'integrate-dropbox' ));
        }
        $downloadLimit = intval( $downloadInfo['limit'] ?? 0 );
        if ( $downloadLimit > 0 && intval( $downloadInfo['downloadCount'] ?? 0 ) >= $downloadLimit ) {
            return new WP_Error(403, __( 'The download limit for this link has been exceeded.', 'integrate-dropbox' ));
        }
        $hashedPassword = md5( sanitize_text_field( $password ) );
        if ( !empty( $downloadInfo['password'] ) ) {
            if ( empty( $password ) ) {
                return new WP_Error(401, __( 'This shared link is protected by a password. Please provide the password to access the file.', 'integrate-dropbox' ));
            }
            if ( $downloadInfo['password'] !== $hashedPassword ) {
                return new WP_Error(401, __( 'The provided password is incorrect.', 'integrate-dropbox' ));
            }
        }
        $downloadInfo['downloadCount'] = intval( $downloadInfo['downloadCount'] ?? 0 ) + 1;
        $downloadInfo['lastViewed'] = current_time( 'mysql' );
        $result = $this->updateDownloadData( $combinedKey, $downloadInfo );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return $downloadData[$linkKey];
    }

    /* ================= PRIVATE ================= */
    private function parseCombinedKey( $sharedKey ) {
        $parts = explode( '-', $sharedKey, 2 );
        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    private function getSharedData( $fileKey ) {
        global $wpdb;
        $file = $wpdb->get_row( $wpdb->prepare( "SELECT metaData FROM %i WHERE fileKey = %s", $this->tableName, $fileKey ) );
        if ( !$file ) {
            return [];
        }
        $metaData = maybe_unserialize( $file->metaData );
        return $metaData['sharedData'] ?? [];
    }

    private function getDownloadData( $fileKey ) {
        global $wpdb;
        $file = $wpdb->get_row( $wpdb->prepare( "SELECT metaData, extension FROM %i WHERE fileKey = %s", $this->tableName, $fileKey ) );
        if ( !$file ) {
            return [];
        }
        if ( $file->extension === 'folder' ) {
            return [];
        }
        $metaData = maybe_unserialize( $file->metaData );
        return $metaData['downloadData'] ?? [];
    }

    private function saveSharedData( $fileKey, $sharedData ) {
        global $wpdb;
        $file = $wpdb->get_row( $wpdb->prepare( "SELECT metaData FROM %i WHERE fileKey = %s", $this->tableName, $fileKey ) );
        if ( !$file ) {
            return false;
        }
        $metaData = maybe_unserialize( $file->metaData ) ?? [];
        $metaData['sharedData'] = $sharedData;
        $result = $wpdb->update(
            $this->tableName,
            [
                'metaData'  => maybe_serialize( $metaData ),
                'updatedAt' => current_time( 'mysql' ),
            ],
            [
                'fileKey' => $fileKey,
            ],
            ['%s', '%s'],
            ['%s']
        );
        if ( $result === false ) {
            return new WP_Error('update_failed', __( 'Failed to update shared data.', 'integrate-dropbox' ));
        }
        return $this->getSharedData( $fileKey );
    }

    private function saveDownloadData( $fileKey, $downloadData ) {
        global $wpdb;
        $file = $wpdb->get_row( $wpdb->prepare( "SELECT metaData FROM %i WHERE fileKey = %s", $this->tableName, $fileKey ) );
        if ( !$file ) {
            return false;
        }
        $metaData = maybe_unserialize( $file->metaData ) ?? [];
        $metaData['downloadData'] = $downloadData;
        $result = $wpdb->update(
            $this->tableName,
            [
                'metaData'  => maybe_serialize( $metaData ),
                'updatedAt' => current_time( 'mysql' ),
            ],
            [
                'fileKey' => $fileKey,
            ],
            ['%s', '%s'],
            ['%s']
        );
        if ( $result === false ) {
            return new WP_Error('update_failed', __( 'Failed to update download data.', 'integrate-dropbox' ));
        }
        return $this->getDownloadData( $fileKey );
    }

    public function saveCachedData( $fileKey, $size ) {
        global $wpdb;
        $file = $wpdb->get_row( $wpdb->prepare( "SELECT metaData FROM %i WHERE fileKey = %s", $this->tableName, $fileKey ) );
        if ( !$file ) {
            return false;
        }
        $metaData = maybe_unserialize( $file->metaData ) ?? [];
        $metaData['cachedData'][$size] = current_time( 'mysql' );
        delete_transient( "idb_dashboard_image_cache" );
        return $wpdb->update(
            $this->tableName,
            [
                'metaData'  => maybe_serialize( $metaData ),
                'updatedAt' => current_time( 'mysql' ),
            ],
            [
                'fileKey' => $fileKey,
            ],
            ['%s', '%s'],
            ['%s']
        );
    }

    /**
     * Remove cachedData from metaData for a file or all files by size
     *
     * @param string|null $fileKey Specific fileKey or null for all files
     * @param string|null $size Specific size to remove or null to remove all sizes
     * @return int Number of records updated
     */
    public function deleteCachedData( $fileKey = null, $size = null ) : int {
        global $wpdb;
        if ( $fileKey ) {
            // Single file - remove cachedData from its metaData
            $file = $wpdb->get_row( $wpdb->prepare( "SELECT metaData FROM %i WHERE fileKey = %s", $this->tableName, $fileKey ) );
            if ( !$file ) {
                return 0;
            }
            $metaData = maybe_unserialize( $file->metaData ) ?? [];
            if ( $size ) {
                // Remove specific size from cachedData
                unset($metaData['cachedData'][$size]);
            } else {
                // Remove entire cachedData array
                unset($metaData['cachedData']);
            }
            $wpdb->update(
                $this->tableName,
                [
                    'metaData'  => maybe_serialize( $metaData ),
                    'updatedAt' => current_time( 'mysql' ),
                ],
                [
                    'fileKey' => $fileKey,
                ],
                ['%s', '%s'],
                ['%s']
            );
            return 1;
        }
        // All files - remove cachedData entries
        if ( $size ) {
            // Get all files that have cachedData for this size
            $files = $wpdb->get_results( $wpdb->prepare( "SELECT fileKey, metaData FROM %i WHERE metaData LIKE %s", $this->tableName, '%' . $wpdb->esc_like( '"cachedData";a:' ) . '%' ) );
            $count = 0;
            foreach ( $files as $file ) {
                $metaData = maybe_unserialize( $file->metaData ) ?? [];
                if ( isset( $metaData['cachedData'][$size] ) ) {
                    unset($metaData['cachedData'][$size]);
                    if ( empty( $metaData['cachedData'] ) ) {
                        unset($metaData['cachedData']);
                    }
                    $wpdb->update(
                        $this->tableName,
                        [
                            'metaData'  => maybe_serialize( $metaData ),
                            'updatedAt' => current_time( 'mysql' ),
                        ],
                        [
                            'fileKey' => $file->fileKey,
                        ],
                        ['%s', '%s'],
                        ['%s']
                    );
                    $count++;
                }
            }
            return $count;
        }
        // Remove all cachedData from all files
        return ( $wpdb->query( $wpdb->prepare(
            "UPDATE %i SET metaData = REPLACE(metaData, %s, 's:0:\"\";'), updatedAt = %s WHERE metaData LIKE %s",
            $this->tableName,
            's:10:"cachedData";',
            current_time( 'mysql' ),
            '%' . $wpdb->esc_like( '"cachedData";a:' ) . '%'
        ) ) ?: 0 );
    }

    public function updateSharedData( $combinedKey, $updates = [] ) {
        [$fileKey, $linkKey] = $this->parseCombinedKey( $combinedKey );
        if ( !$fileKey || !$linkKey ) {
            return false;
        }
        $sharedData = $this->getSharedData( $fileKey );
        if ( empty( $sharedData[$linkKey] ) ) {
            return false;
        }
        // Only update provided fields
        $sharedData[$linkKey] = array_merge( $sharedData[$linkKey], array_filter( $updates, function ( $v ) {
            return $v !== null;
        } ) );
        $result = $this->saveSharedData( $fileKey, $sharedData );
        if ( $result === false ) {
            return new WP_Error('update_failed', __( 'Failed to update shared data.', 'integrate-dropbox' ));
        }
        return $this->getSharedData( $fileKey );
    }

    public function updateDownloadData( $combinedKey, $updates = [] ) {
        [$fileKey, $linkKey] = $this->parseCombinedKey( $combinedKey );
        if ( !$fileKey || !$linkKey ) {
            return false;
        }
        $downloadData = $this->getDownloadData( $fileKey );
        if ( empty( $downloadData[$linkKey] ) ) {
            return false;
        }
        // Only update provided fields
        $downloadData[$linkKey] = array_merge( $downloadData[$linkKey], array_filter( $updates, function ( $v ) {
            return $v !== null;
        } ) );
        $result = $this->saveDownloadData( $fileKey, $downloadData );
        if ( $result === false ) {
            return new WP_Error('update_failed', __( 'Failed to update download data.', 'integrate-dropbox' ));
        }
        return $this->getDownloadData( $fileKey );
    }

    private function deleteSharedEntry( $fileKey, $linkKey ) {
        $sharedData = $this->getSharedData( $fileKey );
        if ( isset( $sharedData[$linkKey] ) ) {
            unset($sharedData[$linkKey]);
            $result = $this->saveSharedData( $fileKey, $sharedData );
            if ( $result === false ) {
                return new WP_Error('update_failed', __( 'Failed to update shared data.', 'integrate-dropbox' ));
            }
            return $this->getSharedData( $fileKey );
        }
        return false;
    }

    private function deleteDownloadEntry( $fileKey, $linkKey ) {
        $downloadData = $this->getDownloadData( $fileKey );
        if ( isset( $downloadData[$linkKey] ) ) {
            unset($downloadData[$linkKey]);
            $result = $this->saveDownloadData( $fileKey, $downloadData );
            if ( $result === false ) {
                return new WP_Error('update_failed', __( 'Failed to update download data.', 'integrate-dropbox' ));
            }
            return $this->getDownloadData( $fileKey );
        }
        return false;
    }

    private function getChildFolderIds( $parentPath, $accountId ) {
        if ( empty( $parentPath ) || empty( $accountId ) ) {
            return [];
        }
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT path FROM %i WHERE parent = %s AND accountId = %s AND extension = 'folder'",
            $this->tableName,
            $parentPath,
            $accountId
        ), ARRAY_A );
    }

    private function processFiles( $files, $returnType = 'array', $filter = null ) {
        $processedFiles = [];
        foreach ( $files as $file ) {
            $processedFiles[] = $this->processFile( $file, $returnType, $filter );
        }
        return $processedFiles;
    }

    private function processFile( $file, $returnType = 'object', $filter = null ) {
        if ( empty( $file ) ) {
            return [];
        }
        $fileData = [
            'id'              => $file->id,
            'fileId'          => $file->fileId,
            'fileKey'         => $file->fileKey,
            'path'            => $file->path,
            'name'            => $file->name,
            'size'            => (int) $file->size,
            'parent'          => $file->parent,
            'accountId'       => $file->accountId,
            'mimeType'        => $file->mimeType,
            'extension'       => $file->extension,
            'thumbnail'       => $file->thumbnail,
            'thumbnailRatio'  => $file->thumbnailRatio,
            'description'     => $file->description,
            'sharedLink'      => $file->sharedLink,
            'isDir'           => $file->isDir,
            'permissions'     => maybe_unserialize( $file->permissions ),
            'hasOwnThumbnail' => $file->hasOwnThumbnail,
            'icon'            => $file->icon,
            'additionalData'  => maybe_unserialize( $file->additionalData ),
            'metaData'        => maybe_unserialize( $file->metaData ),
            'createdAt'       => $file->createdAt,
            'updatedAt'       => $file->updatedAt,
        ];
        $filter = array_merge( [
            'parent'    => $file->path,
            'accountId' => $file->accountId,
        ], $filter ?? [] );
        if ( $file->isDir ) {
            $fileData['size'] = $this->countRecords( $filter );
        }
        if ( $returnType === 'object' ) {
            return new File($fileData);
        } elseif ( $returnType === 'array' ) {
            return $fileData;
        }
        return [
            'id'         => $file->id,
            'name'       => $file->name,
            'key'        => $file->fileKey,
            'mimeType'   => $file->mimeType,
            'size'       => $file->size,
            'thumbnails' => maybe_unserialize( $file->thumbnails ),
        ];
    }

    private function processExtensions( array $extensions, array $additionalExtensions, string $filterType ) : array {
        if ( empty( $additionalExtensions ) ) {
            return $extensions;
        }
        if ( empty( $extensions ) ) {
            return $additionalExtensions;
        }
        if ( $filterType === 'include' ) {
            $filterExtensions = array_filter( $extensions, function ( $ext ) use($additionalExtensions) {
                return in_array( $ext, $additionalExtensions );
            } );
            return array_values( $filterExtensions );
        } elseif ( $filterType === 'exclude' ) {
            $filterExtensions = array_filter( $extensions, fn( $ext ) => !in_array( $ext, $additionalExtensions ) );
            return array_values( $filterExtensions );
        }
        return $extensions;
    }

    private function processFolderTree( array $items, array $rootPaths ) {
        $tree = [];
        $lookup = [];
        foreach ( $items as $item ) {
            $path = rtrim( $item['path'], '/' );
            $lookup[$path] = [
                'name'     => $item['name'],
                'fileKey'  => $item['fileKey'],
                'children' => [],
                'path'     => $path,
                'parent'   => rtrim( $item['parent'], '/' ),
            ];
        }
        foreach ( $lookup as $path => &$node ) {
            if ( in_array( $node['path'], $rootPaths, true ) || $node['parent'] === '' || $node['parent'] === '/' ) {
                unset($node['parent']);
                $tree[] =& $node;
            } else {
                if ( isset( $lookup[$node['parent']] ) ) {
                    $lookup[$node['parent']]['children'][] =& $node;
                }
            }
        }
        // foreach ($lookup as $path => &$node) {
        //     if ($node['parent'] === '' || $node['parent'] === '/') {
        //         unset($node['parent']);
        //         $tree[] = &$node;
        //     } else {
        //         if (isset($lookup[$node['parent']])) {
        //             $lookup[$node['parent']]['children'][] = &$node;
        //         }
        //     }
        // }
        $tree = array_map( function ( $item ) {
            if ( empty( $item['children'] ) ) {
                unset($item['children']);
            }
            return $item;
        }, $tree );
        return array_values( $tree );
    }

    public function sharedFiles( array $args = [] ) {
        return $this->filesByMetaString( '"sharedData";a:', $args );
    }

    public function downloadedFiles( array $args = [] ) {
        return $this->filesByMetaString( '"downloadData";a:', $args );
    }

    public function cachedFiles( array $args = [] ) {
        return $this->filesByMetaString( '"cachedData";a:', $args );
    }

    private function filesByMetaString( string $string, array $args = [] ) {
        global $wpdb;
        $defaults = [
            'accountId' => null,
            'perPage'   => 5,
            'page'      => 1,
            'orderBy'   => 'updatedAt',
            'order'     => 'DESC',
        ];
        $args = wp_parse_args( $args, $defaults );
        $accountId = $args['accountId'];
        if ( empty( $accountId ) ) {
            $account = Accounts::getInstance()->getAccount();
            $accountId = ( $account ? $account->id : null );
        }
        $perPage = (int) $args['perPage'];
        $page = (int) $args['page'];
        $pagination = $this->sanitizePagination( $page, $perPage );
        $order = $this->sanitizeOrder( $args['order'] );
        $orderBy = $this->sanitizeOrderBy( $args['orderBy'], [
            'name',
            'createdAt',
            'updatedAt',
            'size'
        ] );
        $sql = $wpdb->prepare( "SELECT * FROM %i WHERE `metaData` LIKE %s", $this->tableName, '%' . $wpdb->esc_like( $string ) . '%' );
        $countSql = $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE `metaData` LIKE %s", $this->tableName, '%' . $wpdb->esc_like( $string ) . '%' );
        if ( $args['accountId'] ) {
            $sql .= $wpdb->prepare( " AND `accountId` = %s", $args['accountId'] );
            $countSql .= $wpdb->prepare( " AND `accountId` = %s", $args['accountId'] );
        }
        if ( $order === 'ASC' ) {
            $sql .= $wpdb->prepare(
                " ORDER BY %i ASC LIMIT %d OFFSET %d",
                $orderBy,
                $pagination['perPage'],
                $pagination['offset']
            );
        } else {
            $sql .= $wpdb->prepare(
                " ORDER BY %i DESC LIMIT %d OFFSET %d",
                $orderBy,
                $pagination['perPage'],
                $pagination['offset']
            );
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $files = $wpdb->get_results( $sql );
        $total = $wpdb->get_var( $countSql );
        if ( is_wp_error( $files ) ) {
            return $files;
        }
        if ( empty( $files ) ) {
            return [
                'files'       => [],
                'total'       => 0,
                'perPage'     => $pagination['perPage'],
                'currentPage' => $pagination['page'],
                'totalPages'  => 0,
                'hasMore'     => false,
                'nextPage'    => null,
            ];
        }
        $files = $this->processFiles( $files );
        $result = [
            'files'       => $files,
            'total'       => (int) $total,
            'perPage'     => $pagination['perPage'],
            'currentPage' => $pagination['page'],
            'totalPages'  => ceil( $total / $pagination['perPage'] ),
            'hasMore'     => $pagination['page'] * $pagination['perPage'] < $total,
            'nextPage'    => ( $pagination['page'] * $pagination['perPage'] < $total ? $pagination['page'] + 1 : null ),
        ];
        return $result;
    }

}

// phpcs:disable WordPress.DB.DirectDatabaseQuery