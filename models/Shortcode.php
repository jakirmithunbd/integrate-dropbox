<?php

namespace CodeConfig\IDB\Models;

use function array_key_exists;
use CodeConfig\IDB\App\App;
use function CodeConfig\IDB\ccpidb_fs;
use CodeConfig\IDB\Shortcode as IDBShortcode;
use CodeConfig\IDB\Utils\Helpers;
use CodeConfig\IDB\Utils\Singleton;
use function count;
use function in_array;
use function intval;
use function is_array;
use function is_string;
use WP_Error;
defined( 'ABSPATH' ) || exit( 'No direct script access allowed' );
// phpcs:disable WordPress.DB.DirectDatabaseQuery
class Shortcode extends BaseModel {
    use Singleton;
    public function __construct() {
        parent::__construct( 'ccpidb_shortcodes' );
    }

    /**
     * Retrieve a shortcode by its ID.
     *
     * @param int $id The ID of the shortcode to retrieve.
     * @return array|WP_Error Array containing shortcode data or WP_Error if the ID is invalid or an error occurs.
     */
    public function get( $id, array $config = [] ) {
        if ( empty( $id ) ) {
            return new WP_Error(404, __( 'Shortcode ID is required.', 'integrate-dropbox' ));
        }
        $cacheKey = "ccpidb_shortcode__{$id}__" . md5( serialize( $config ) );
        $cacheData = wp_cache_get( $cacheKey, "ccpidb_shortcode__{$id}" );
        if ( $cacheData !== false ) {
            return $cacheData;
        }
        global $wpdb;
        $shortcode = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id = %d", $this->tableName, $id ), ARRAY_A );
        if ( empty( $shortcode ) ) {
            return new WP_Error(404, __( 'Shortcode not found.', 'integrate-dropbox' ), [
                'status' => 404,
            ]);
        }
        $processed = $this->processData( $shortcode, $config );
        wp_cache_set( $cacheKey, $processed, "ccpidb_shortcode__{$id}" );
        return $processed;
    }

    public function getAll( array $config ) {
        global $wpdb;
        $defaults = [
            'type'    => 'all',
            'search'  => '',
            'status'  => 'all',
            'order'   => 'DESC',
            'orderBy' => 'createdAt',
            'page'    => 1,
            'perPage' => 10,
        ];
        $config = array_merge( $defaults, $config );
        $cacheKey = 'ccpidb_shortcodes__' . md5( serialize( $config ) );
        $cacheData = wp_cache_get( $cacheKey, 'ccpidb_shortcodes' );
        if ( $cacheData !== false ) {
            return $cacheData;
        }
        $allowedOrderBy = [
            'title',
            'type',
            'status',
            'id',
            'createdAt',
            'updatedAt'
        ];
        $orderBy = $this->sanitizeOrderBy( $config['orderBy'], $allowedOrderBy );
        $order = $this->sanitizeOrder( $config['order'] );
        $pagination = $this->sanitizePagination( $config['page'], $config['perPage'] );
        $sqlParts = $wpdb->prepare( "SELECT * FROM %i WHERE 1=1", $this->tableName );
        if ( $config['type'] !== 'all' ) {
            $sqlParts .= $wpdb->prepare( " AND type = %s", $config['type'] );
        }
        if ( $config['status'] !== 'all' ) {
            $sqlParts .= $wpdb->prepare( " AND status = %s", $config['status'] );
        }
        if ( !empty( $config['search'] ) ) {
            $sqlParts .= $wpdb->prepare( " AND title LIKE %s", '%' . $config['search'] . '%' );
        }
        if ( $order === 'ASC' ) {
            $sqlParts .= $wpdb->prepare( " ORDER BY %i ASC", $orderBy );
        } else {
            $sqlParts .= $wpdb->prepare( " ORDER BY %i DESC", $orderBy );
        }
        if ( $pagination['perPage'] > 0 ) {
            $sqlParts .= $wpdb->prepare( " LIMIT %d OFFSET %d", $pagination['perPage'], $pagination['offset'] );
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $results = $wpdb->get_results( $sqlParts, ARRAY_A );
        if ( is_wp_error( $results ) ) {
            return $results;
        }
        $processData = [];
        foreach ( $results as $result ) {
            $processData[] = $this->processData( $result, [
                'dataProcess'    => false,
                'validateSchema' => false,
            ] );
        }
        wp_cache_set( $cacheKey, $processData, 'ccpidb_shortcodes' );
        return $processData;
    }

    public function add( array $data, $force = false ) {
        if ( !in_array( $data['type'], IDBShortcode::getModulesList() ) ) {
            return new WP_Error(401, __( 'Invalid shortcode type.', 'integrate-dropbox' ), [
                'status' => 400,
            ]);
        }
        $now = current_time( 'mysql' );
        $is_update = !empty( $data['id'] ) && is_numeric( $data['id'] );
        if ( $force && $is_update ) {
            $exists = $this->shortcodeExists( (int) $data['id'] );
            if ( !$exists ) {
                $is_update = false;
            }
        }
        if ( !empty( $data['data'] ) && is_array( $data['data'] ) ) {
            $data['data'] = $this->processAndSerializeModuleData( $data['type'], $data['data'] );
        }
        if ( !empty( $data['locations'] ) && is_array( $data['locations'] ) ) {
            $data['locations'] = maybe_serialize( $data['locations'] );
        }
        global $wpdb;
        if ( $is_update ) {
            $id = (int) $data['id'];
            unset($data['id'], $data['createdAt']);
            if ( !$force ) {
                unset($data['locations']);
            }
            $data['updatedAt'] = $now;
            $format = $this->generateFormat( $data );
            $where_format = ['%d'];
            $result = $wpdb->update(
                $this->tableName,
                $data,
                [
                    'id' => $id,
                ],
                $format,
                $where_format
            );
            if ( $result === false ) {
                return new WP_Error(400, __( 'Failed to update shortcode.', 'integrate-dropbox' ), [
                    'status' => 500,
                ]);
            }
            wp_cache_flush_group( "ccpidb_shortcode__{$id}" );
            wp_cache_flush_group( "ccpidb_shortcodes" );
            $shortcode = $this->getShortcode( $id );
            return $this->processData( $shortcode );
        } else {
            if ( empty( $data['type'] ) ) {
                return new WP_Error(401, __( 'Shortcode type is required.', 'integrate-dropbox' ), [
                    'status' => 400,
                ]);
            }
            if ( empty( $data['data'] ) ) {
                return new WP_Error(401, __( 'Shortcode data is required.', 'integrate-dropbox' ), [
                    'status' => 400,
                ]);
            }
            $data['title'] ??= 'Untitled';
            $data['status'] ??= 'active';
            $data['integration'] ??= '';
            $data['createdAt'] = $now;
            $data['updatedAt'] = $now;
            $format = $this->generateFormat( $data );
            $inserted = $wpdb->insert( $this->tableName, $data, $format );
            if ( $inserted === false ) {
                return new WP_Error(401, __( 'Failed to insert shortcode.', 'integrate-dropbox' ), [
                    'status' => 500,
                ]);
            }
            $id = (int) $wpdb->insert_id;
            wp_cache_flush_group( "ccpidb_shortcodes" );
            return $this->processData( $this->getShortcode( $id ) );
        }
    }

    public function insertFile( $id, $fileKey ) {
        $shortcode = $this->getShortcode( $id );
        if ( is_wp_error( $shortcode ) ) {
            return $shortcode;
        }
        if ( empty( $shortcode['data']['source']['fileKeys'] ) || !is_array( $shortcode['data']['source']['fileKeys'] ) ) {
            $shortcode['data']['source']['fileKeys'] = [];
        }
        foreach ( $shortcode['data']['source']['fileKeys'] as $existingFile ) {
            if ( isset( $existingFile['fileKey'] ) && $existingFile['fileKey'] === $fileKey ) {
                return true;
            }
        }
        $shortcode['data']['source']['fileKeys'][] = [
            'fileKey'      => $fileKey,
            'thumbnailKey' => '',
        ];
        return $this->add( $shortcode );
    }

    public function getShortcode( int $id, string $key = '' ) {
        if ( empty( $id ) ) {
            return new WP_Error(403, __( 'Invalid ID provided.', 'integrate-dropbox' ), [
                'status' => 404,
            ]);
        }
        $cacheKey = "ccpidb_shortcode__{$id}__{$key}";
        $cacheData = wp_cache_get( $cacheKey, "ccpidb_shortcode__{$id}" );
        if ( $cacheData !== false ) {
            return $cacheData;
        }
        global $wpdb;
        $shortcode = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id = %d", $this->tableName, $id ), ARRAY_A );
        if ( is_wp_error( $shortcode ) ) {
            return $shortcode;
        }
        if ( empty( $shortcode ) ) {
            return new WP_Error(404, __( 'Shortcode not found.', 'integrate-dropbox' ), [
                'status' => 404,
            ]);
        }
        if ( isset( $shortcode['data'] ) && is_serialized( $shortcode['data'] ) ) {
            $shortcode['data'] = maybe_unserialize( $shortcode['data'] );
        }
        if ( empty( $key ) ) {
            return $shortcode;
        }
        if ( strpos( $key, '.' ) !== false ) {
            $keys = explode( '.', $key );
            $value = $shortcode;
            foreach ( $keys as $innerKey ) {
                if ( !is_array( $value ) || !array_key_exists( $innerKey, $value ) ) {
                    return null;
                }
                $value = $value[$innerKey];
            }
            wp_cache_set( $cacheKey, $value, "ccpidb_shortcode__{$id}" );
            return $value;
        }
        $result = $shortcode[$key] ?? null;
        if ( !empty( $result ) ) {
            wp_cache_set( $cacheKey, $result, "ccpidb_shortcode__{$id}" );
        }
        return $result;
    }

    /**
     * Delete shortcodes from the database.
     *
     * @param int|array $ids The ID or IDs of the shortcodes to delete.
     * @return int|WP_Error The number of rows affected or a WP_Error object if an error occurs.
     */
    public function remove( $ids ) {
        if ( !is_array( $ids ) ) {
            $ids = [$ids];
        }
        if ( empty( $ids ) ) {
            return 0;
        }
        foreach ( $ids as $id ) {
            if ( !is_numeric( $id ) ) {
                return new WP_Error(403, __( 'Invalid ID provided.', 'integrate-dropbox' ), [
                    'status' => 400,
                ]);
            }
        }
        $success_count = 0;
        global $wpdb;
        foreach ( $ids as $id ) {
            $result = $wpdb->delete( $this->tableName, [
                'id' => (int) $id,
            ], ['%d'] );
            if ( $result !== false ) {
                wp_cache_flush_group( "ccpidb_shortcode__{$id}" );
                $success_count++;
            }
        }
        if ( $success_count === 0 ) {
            return new WP_Error(401, __( 'Failed to delete any shortcodes.', 'integrate-dropbox' ), [
                'status' => 500,
            ]);
        }
        wp_cache_flush_group( "ccpidb_shortcodes" );
        return $success_count;
    }

    public function duplicate( $ids ) {
        if ( !is_array( $ids ) ) {
            $ids = [$ids];
        }
        if ( empty( $ids ) ) {
            return new WP_Error(403, __( 'Invalid ID provided.', 'integrate-dropbox' ), [
                'status' => 404,
            ]);
        }
        foreach ( $ids as $id ) {
            if ( !is_numeric( $id ) ) {
                return new WP_Error(403, __( 'Invalid ID provided.', 'integrate-dropbox' ), [
                    'status' => 404,
                ]);
            }
        }
        $shortcodes = [];
        foreach ( $ids as $id ) {
            $shortcode = $this->getShortcode( $id );
            if ( is_wp_error( $shortcode ) ) {
                return $shortcode;
            }
            if ( !empty( $shortcode ) ) {
                $shortcodes[] = $shortcode;
            }
        }
        if ( empty( $shortcodes ) ) {
            return new WP_Error(404, __( 'Shortcode not found.', 'integrate-dropbox' ), [
                'status' => 404,
            ]);
        }
        global $wpdb;
        $results = 0;
        foreach ( $shortcodes as $shortcode ) {
            $shortcode['title'] .= ' - Copy';
            $shortcode['status'] = 'inactive';
            unset($shortcode['id']);
            $shortcode['createdAt'] = current_time( 'mysql' );
            $shortcode['updatedAt'] = current_time( 'mysql' );
            $result = $wpdb->insert( $this->tableName, $shortcode, $this->generateFormat( $shortcode ) );
            if ( $result === false ) {
                return new WP_Error(401, __( 'Failed to insert shortcode.', 'integrate-dropbox' ), [
                    'status' => 500,
                ]);
            }
            $results++;
        }
        wp_cache_flush_group( "ccpidb_shortcodes" );
        return $results;
    }

    public function import( $shortcodesData ) {
        $importedCount = 0;
        foreach ( $shortcodesData as $shortcodeData ) {
            $data = [
                'id'          => $shortcodeData['id'] ?? null,
                'title'       => $shortcodeData['title'] ?? 'Untitled',
                'type'        => $shortcodeData['type'] ?? '',
                'status'      => $shortcodeData['status'] ?? 'inactive',
                'integration' => $shortcodeData['integration'] ?? '',
                'locations'   => maybe_unserialize( $shortcodeData['locations'] ?? [] ) ?? [],
                'data'        => maybe_unserialize( $shortcodeData['data'] ?? [] ) ?? [],
            ];
            $validatedData = $this->validateShortcodeData( $data );
            if ( empty( $validatedData ) ) {
                continue;
            }
            $result = $this->add( $validatedData, true );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            $importedCount++;
        }
        wp_cache_flush_group( "ccpidb_shortcodes" );
        return $importedCount;
    }

    private function validateShortcodeData( $data ) {
        if ( empty( $data ) || !is_array( $data ) || empty( $data['type'] ) || !in_array( $data['type'], IDBShortcode::getModulesList() ) ) {
            return [];
        }
        $sanitizedData = [];
        if ( is_string( $data ) && is_serialized( $data ) ) {
            $data = maybe_unserialize( $data );
        }
        $defaultModuleData = ccpidbGetModuleDefaultData( $data['type'] );
        if ( is_wp_error( $defaultModuleData ) && empty( $defaultModuleData ) ) {
            return [];
        }
        foreach ( $defaultModuleData as $key => $value ) {
            if ( !empty( $data[$key] ) ) {
                if ( is_array( $value ) ) {
                    if ( is_array( $data[$key] ) ) {
                        $sanitizedData[$key] = ( is_array( $data[$key] ) ? $data[$key] : $value );
                    } else {
                        $sanitizedData[$key] = $value;
                    }
                } else {
                    $sanitizedData[$key] = $data[$key];
                }
            } else {
                $sanitizedData[$key] = $value;
            }
        }
        return $sanitizedData;
    }

    // ========================= Utility methods =========================
    /**
     * Check if a shortcode exists by ID.
     *
     * @param int $id The shortcode ID.
     * @return bool True if exists, false otherwise.
     */
    public function shortcodeExists( $id ) {
        return $this->recordExists( [
            'id' => (int) $id,
        ], [
            'id' => '%d',
        ] );
    }

    /**
     * Get a specific column value for a shortcode.
     *
     * @param string $column The column title.
     * @param int $id The shortcode ID.
     * @return mixed|null The column value or null if not found.
     */
    public function getShortcodeColumn( $column, $id ) {
        return $this->getColumnValue( $column, [
            'id' => (int) $id,
        ], ['%d'] );
    }

    /**
     * Get shortcode title by ID.
     *
     * @param int $id The shortcode ID.
     * @return string|null The shortcode title or null if not found.
     */
    public function getShortcodeTitle( $id ) {
        return $this->getColumnValue( 'title', [
            'id' => (int) $id,
        ], ['%d'] );
    }

    /**
     * Update shortcode status.
     *
     * @param int $id The shortcode ID.
     * @param string $status The new status.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function updateStatus( $id, $status ) {
        // return $this->updateRecords(
        //     ['status' => $status, 'updated_at' => current_time('mysql')],
        //     ['id' => (int) $id],
        //     ['%s', '%s'],
        //     ['%d']
        // );
        global $wpdb;
        $result = $wpdb->update(
            $this->tableName,
            [
                'status'    => $status,
                'updatedAt' => current_time( 'mysql' ),
            ],
            [
                'id' => (int) $id,
            ],
            ['%s', '%s'],
            ['%d']
        );
        if ( $result === false ) {
            return new WP_Error(400, __( 'Failed to update shortcode status.', 'integrate-dropbox' ), [
                'status' => 500,
            ]);
        }
        wp_cache_flush_group( "ccpidb_shortcode__{$id}" );
        wp_cache_flush_group( "ccpidb_shortcodes" );
        return true;
    }

    // ========================= Private methods =========================
    private function generateFormat( $data ) {
        $format = [];
        foreach ( $data as $key => $value ) {
            $format[] = ( is_numeric( $value ) && (int) $value == $value ? '%d' : '%s' );
        }
        return $format;
    }

    /**
     * Processes the input data for a shortcode, handling serialization, file retrieval,
     * and optional schema validation and sanitization.
     *
     * @param array $data The data array containing 'type' and serialized 'data'.
     * @param array $config Optional configuration for processing, including:
     *
     * @return array|WP_Error Processed and optionally validated data.
     */
    private function processData( $data, $config = [] ) {
        if ( empty( $data['type'] ) || empty( $data['data'] ) ) {
            return new WP_Error(401, __( 'Invalid shortcode data.', 'integrate-dropbox' ), [
                'status' => 400,
            ]);
        }
        $moduleType = $data['type'] ?? '';
        $id = $data['id'] ?? 0;
        if ( empty( $id ) ) {
            return new WP_Error(403, __( 'Invalid shortcode ID.', 'integrate-dropbox' ), [
                'status' => 400,
            ]);
        }
        $default = [
            'validateSchema' => true,
            'returnType'     => 'array',
            'recursive'      => !in_array( $moduleType, ['file-browser', 'file-uploader', 'file-list'] ),
            'page'           => 1,
            'fileKey'        => null,
            'order'          => null,
            'orderBy'        => null,
            'search'         => null,
            'searchScope'    => 'folder',
            'from'           => 'cache',
            'password'       => null,
            'moduleType'     => $moduleType,
            'dataProcess'    => true,
            'shortcodeId'    => $id,
        ];
        // $wp_referer    = wp_get_raw_referer();
        $queryConfig = wp_parse_args( $config, $default );
        $isAdmin = ccpidbHasUserAccessPage( 'module_builder' ) && ($queryConfig['isAdmin'] ?? false);
        $validateSchema = ( $queryConfig['validateSchema'] ?? true ?: true );
        $fileKey = ( $queryConfig['fileKey'] ?? null ?: null );
        $order = ( $queryConfig['order'] ?? null ?: null );
        $orderBy = ( $queryConfig['orderBy'] ?? null ?: null );
        $password = ( $queryConfig['password'] ?? null ?: null );
        $processedData = [];
        foreach ( $data as $key => $value ) {
            if ( is_serialized( $value ) ) {
                $value = unserialize( $value );
                if ( $key === 'data' && $queryConfig['dataProcess'] ) {
                    if ( !in_array( $data['type'], IDBShortcode::getModulesList() ) ) {
                        $processedData[$key] = [];
                        continue;
                    }
                    $permissions = $value['permissions'] ?? [];
                    if ( !empty( $permissions ) && !ccpidbHasUserAccessPage( 'module_builder' ) ) {
                        $passwordProtect = $permissions['passwordProtect'] ?? '';
                        if ( isset( $passwordProtect['enable'] ) && $passwordProtect['enable'] && isset( $passwordProtect['password'] ) && !empty( $passwordProtect['password'] ) ) {
                            $storedPassword = $passwordProtect['password'];
                            $cookieKey = "ccpidb_token_{$id}";
                            $secure_hash = hash( 'sha256', $storedPassword );
                            if ( isset( $_COOKIE[$cookieKey] ) && sanitize_text_field( wp_unslash( $_COOKIE[$cookieKey] ) ) !== $secure_hash || empty( $_COOKIE[$cookieKey] ) ) {
                                if ( empty( $password ) ) {
                                    $value['source']['files'] = new WP_Error(401, __( 'Password is required', 'integrate-dropbox' ), [
                                        'status' => 401,
                                    ]);
                                    $processedData[$key] = $value;
                                    return $processedData;
                                }
                                $new_hash = hash( 'sha256', $password );
                                if ( $secure_hash !== $new_hash ) {
                                    $value['source']['files'] = new WP_Error(401, __( 'Password is incorrect', 'integrate-dropbox' ), [
                                        'status' => 401,
                                    ]);
                                    $processedData[$key] = $value;
                                    Notices::getInstance()->add( [
                                        'type'        => 'error',
                                        'title'       => __( 'Password Error', 'integrate-dropbox' ),
                                        'description' => sprintf(
                                            "A User '%s' tried to access #%d: %s module with an incorrect password.",
                                            wp_get_current_user()->user_login ?? 'Guest',
                                            $id,
                                            $moduleType
                                        ),
                                    ] );
                                    return $processedData;
                                } else {
                                    setcookie(
                                        $cookieKey,
                                        $secure_hash,
                                        time() + DAY_IN_SECONDS,
                                        COOKIEPATH,
                                        COOKIE_DOMAIN,
                                        is_ssl(),
                                        true
                                    );
                                }
                            }
                        }
                    }
                    $sourceFileKeys = $value['source']['fileKeys'] ?? [];
                    $fileKeys = $sourceFileKeys;
                    if ( empty( $fileKeys ) ) {
                        return new WP_Error(401, __( 'No file keys specified in the shortcode data.', 'integrate-dropbox' ), [
                            'status' => 400,
                        ]);
                    }
                    if ( !empty( $fileKey ) && $fileKey !== '/' && $fileKey !== '' ) {
                        $fileKeys = array_column( $fileKeys, 'fileKey' );
                        if ( Helpers::validateFileKey( $fileKey, $fileKeys ) ) {
                            $fileKeys = [[
                                'fileKey'      => $fileKey,
                                'thumbnailKey' => '',
                            ]];
                            $queryConfig['recursive'] = true;
                        } else {
                            return new WP_Error(401, __( 'The specified file key is not allowed for this shortcode.', 'integrate-dropbox' ), [
                                'status' => 403,
                            ]);
                        }
                    } elseif ( $moduleType === 'file-uploader' && !$isAdmin ) {
                        $uploadKeys = json_decode( sanitize_text_field( wp_unslash( $_COOKIE["ccpidb_file_uploader_files_{$id}"] ?? '' ) ), true );
                        if ( empty( $uploadKeys ) || !is_array( $uploadKeys ) || count( $fileKeys ) > 1 ) {
                            $processedData[$key] = $value;
                            continue;
                        }
                        $uploadRootFileKey = $sourceFileKeys[0]['fileKey'] ?? '';
                        if ( empty( $uploadRootFileKey ) ) {
                            $processedData[$key] = $value;
                            continue;
                        }
                        $uploadRootFile = Files::getInstance()->getFile( $uploadRootFileKey );
                        if ( is_wp_error( $uploadRootFile ) || empty( $uploadRootFile ) ) {
                            $processedData[$key] = $value;
                            continue;
                        }
                        $files = Files::getInstance()->getFileAttributesByKeys( $uploadKeys, ['parent', 'fileKey'] );
                        if ( is_wp_error( $files ) || empty( $files ) ) {
                            $processedData[$key] = $value;
                            continue;
                        }
                        $filterUploadKeys = [];
                        foreach ( $files as $uploadFile ) {
                            if ( $uploadFile['parent'] === $uploadRootFile->path ) {
                                $filterUploadKeys[] = [
                                    'fileKey'      => $uploadFile['fileKey'],
                                    'thumbnailKey' => '',
                                ];
                            }
                        }
                        $fileKeys = ( !empty( $filterUploadKeys ) ? $filterUploadKeys : $fileKeys );
                    } elseif ( $moduleType === 'search-box' && empty( $queryConfig['search'] ) ) {
                        $value['source']['files'] = [];
                        if ( $isAdmin ) {
                            $selectedFiles = $this->getSelectedFiles( $fileKeys, $queryConfig );
                            if ( !is_wp_error( $selectedFiles ) ) {
                                if ( $moduleType === 'media-player' ) {
                                    $selectedFiles = $this->attachThumbnailsToFiles( $selectedFiles, $sourceFileKeys );
                                }
                                $value['source']['selectedFiles'] = $selectedFiles;
                            }
                        }
                        $processedData[$key] = $value;
                        continue;
                    }
                    $advancedTab = $value['advanced'] ?? false;
                    if ( $advancedTab ) {
                        $queryConfig['perPage'] ??= $advancedTab['files']['perPage'] ?? self::DEFAULT_ITEMS_PER_PAGE;
                        if ( isset( $advancedTab['fileBrowser'] ) && !empty( $advancedTab['fileBrowser'] ) ) {
                            $queryConfig['orderBy'] = $advancedTab['sort']['orderBy'] ?? 'name';
                            $queryConfig['order'] = strtoupper( $advancedTab['sort']['order'] ?? 'ASC' );
                            $queryConfig['from'] = 'cache';
                        } elseif ( isset( $advancedTab['fileList'] ) && !empty( $advancedTab['fileList'] ) ) {
                            $folderExpandable = $advancedTab['fileList']['folderExpandable'] ?? false;
                            $folderRecursive = $advancedTab['fileList']['folderRecursive'] ?? true;
                            $queryConfig['folderExpandable'] = $folderExpandable;
                            if ( $folderRecursive && !$folderExpandable ) {
                                $queryConfig['recursive'] = true;
                            } elseif ( !$folderRecursive && !$folderExpandable ) {
                                $queryConfig['recursive'] = false;
                            }
                            $queryConfig['orderBy'] = $advancedTab['sort']['orderBy'] ?? 'name';
                            $queryConfig['order'] = strtoupper( $advancedTab['sort']['order'] ?? 'ASC' );
                        } else {
                            if ( empty( $this->isModuleAutoFetch( $id, $advancedTab ?? [] ) ) ) {
                                $queryConfig['from'] = 'cache';
                            }
                            $queryConfig['orderBy'] = $advancedTab['sort']['orderBy'] ?? 'name';
                            $queryConfig['order'] = strtoupper( $advancedTab['sort']['order'] ?? 'ASC' );
                        }
                    }
                    if ( !empty( $value['filter'] ) ) {
                        // Extensions filter
                        $extensionsFilter = $value['filter']['extension'] ?? [];
                        $allowAllExtensions = $extensionsFilter['all'] ?? true;
                        $include = $extensionsFilter['include'] ?? [];
                        $exclude = $extensionsFilter['exclude'] ?? [];
                        $extensions = ( $allowAllExtensions ? $exclude : $include );
                        $extensionsFilterType = ( $allowAllExtensions ? 'exclude' : 'include' );
                        $queryConfig['extensions'] = $extensions;
                        $queryConfig['extensionsFilterType'] = $extensionsFilterType;
                        $queryConfig['applyNameFilter'] = [];
                        $queryConfig['names'] = '';
                    }
                    $app = App::getInstance();
                    $filesData = $app->getFilesByKeys( $fileKeys, $queryConfig );
                    if ( empty( $filesData ) && empty( $queryConfig['search'] ) ) {
                        $queryConfig['from'] = 'server';
                        $filesData = $app->getFilesByKeys( $fileKeys, $queryConfig );
                    }
                    if ( is_wp_error( $filesData ) ) {
                        $processedData['error'] = [
                            'code'    => $filesData->get_error_code(),
                            'message' => $filesData->get_error_message(),
                        ];
                        continue;
                    }
                    $files = $filesData['files'] ?? [];
                    $perPage = ( isset( $queryConfig['perPage'] ) ? (int) $queryConfig['perPage'] : self::DEFAULT_ITEMS_PER_PAGE );
                    $totalCount = ( isset( $filesData['totalFiles'] ) ? (int) $filesData['totalFiles'] : count( $filesData['files'] ?? [] ) );
                    $currentPage = ( isset( $filesData['currentPage'] ) ? (int) $filesData['currentPage'] : 1 );
                    $totalPages = ( isset( $filesData['totalPages'] ) ? (int) $filesData['totalPages'] : ceil( $totalCount / $perPage ) );
                    $hasMore = ( isset( $filesData['hasMore'] ) ? (bool) $filesData['hasMore'] : $currentPage < $totalPages );
                    $value['source']['totalCount'] = $totalCount;
                    $value['source']['currentPage'] = $currentPage;
                    $value['source']['perPage'] = $perPage;
                    $value['source']['totalPages'] = $totalPages;
                    $value['source']['hasMore'] = $hasMore;
                    if ( $moduleType === 'file-browser' || $moduleType === 'file-uploader' || $moduleType === 'search-box' ) {
                        $breadcrumbKey = $sourceFileKeys[0]['fileKey'] ?? null;
                        $breadcrumbsArgs = [
                            'rootFileKey' => $breadcrumbKey,
                        ];
                        if ( ($moduleType === 'file-uploader' || $moduleType === 'search-box') && !empty( $breadcrumbKey ) ) {
                            $breadcrumbsArgs = [
                                'rootFolderKey' => $breadcrumbKey ?? '/',
                            ];
                        }
                        $breadcrumbs = Files::getInstance()->getBreadcrumbByKey( $fileKey, $breadcrumbsArgs );
                        if ( is_array( $breadcrumbs ) && !empty( $breadcrumbs ) && !is_wp_error( $breadcrumbs ) ) {
                            $value['source']['breadcrumbs'] = array_reverse( $breadcrumbs );
                        }
                    } elseif ( $moduleType === 'media-player' ) {
                        $files = $this->attachThumbnailsToFiles( $files, $sourceFileKeys );
                    }
                    $value['source']['files'] = $files;
                    $value['source']['nextPage'] = ( $hasMore ? $currentPage + 1 : null );
                    if ( $isAdmin ) {
                        $selectedFiles = $this->getSelectedFiles( $fileKeys, $queryConfig );
                        if ( !is_wp_error( $selectedFiles ) ) {
                            if ( $moduleType === 'media-player' ) {
                                $selectedFiles = $this->attachThumbnailsToFiles( $selectedFiles, $sourceFileKeys );
                            }
                            $value['source']['selectedFiles'] = $selectedFiles;
                        }
                    }
                }
                $processedData[$key] = $value;
            } else {
                $processedData[$key] = ( $key === 'id' ? intval( $value ) : $value );
            }
        }
        if ( $validateSchema || !ccpidbHasUserAccessPage( 'module_builder' ) ) {
            $type = $processedData['type'] ?? '';
            if ( empty( $type ) ) {
                return new WP_Error(401, __( 'Invalid shortcode type.', 'integrate-dropbox' ), [
                    'status' => 400,
                ]);
            }
            $schema = ccpidbGetShortcodeTypesSchema( $type );
            if ( empty( $schema ) ) {
                return new WP_Error(401, __( 'Unsupported shortcode type for schema validation.', 'integrate-dropbox' ), [
                    'status' => 400,
                ]);
            }
            $processedData = $this->validateAndSanitize( $processedData, $schema );
        }
        return $processedData;
    }

    private function validateAndSanitize( array $data, array $schema ) {
        $result = [];
        $schema['data']['source']['selectedFiles[]'] = $schema['data']['source']['files[]'] ?? 'null';
        foreach ( $schema as $key => $expectedType ) {
            $filteredKey = str_replace( '[]', '', $key );
            if ( !isset( $data[$filteredKey] ) ) {
                continue;
            }
            $value = $data[$filteredKey];
            if ( is_array( $expectedType ) ) {
                if ( is_array( $value ) ) {
                    $isNestedArray = strpos( $key, '[]' ) !== false;
                    if ( $isNestedArray && !empty( $value ) ) {
                        foreach ( $value as $index => $item ) {
                            $nested = $this->validateAndSanitize( $item, $expectedType );
                            if ( !empty( $nested ) ) {
                                $result[$filteredKey][$index] = $nested;
                            }
                        }
                    } else {
                        $nested = $this->validateAndSanitize( $value, $expectedType );
                        $result[$filteredKey] = ( !empty( $nested ) ? $nested : [] );
                    }
                }
            } else {
                if ( $this->isTypeMatch( $value, $expectedType ) ) {
                    $result[$filteredKey] = $value;
                }
            }
        }
        return $result;
    }

    private function isTypeMatch( $value, $type ) {
        $types = explode( '|', $type );
        foreach ( $types as $t ) {
            switch ( $t ) {
                case 'integer':
                    if ( is_int( $value ) || is_numeric( $value ) ) {
                        return true;
                    }
                    break;
                case 'string':
                    if ( is_string( $value ) ) {
                        return true;
                    }
                    break;
                case 'boolean':
                    if ( is_bool( $value ) ) {
                        return true;
                    }
                    break;
                case 'array':
                    if ( is_array( $value ) ) {
                        return true;
                    }
                    break;
                case 'object':
                    if ( is_object( $value ) ) {
                        return true;
                    }
                    break;
                case 'NULL':
                    if ( $value === null ) {
                        return true;
                    }
                    break;
                case 'any':
                    return true;
                default:
                    if ( gettype( $value ) === $t ) {
                        return true;
                    }
            }
        }
        return false;
    }

    private function getSelectedFiles( $fileKeys, $args ) {
        $config = [
            'recursive'  => false,
            'returnType' => 'array',
            'page'       => 1,
            'perPage'    => 1000,
            'from'       => 'cache',
        ];
        $config = wp_parse_args( $config, $args );
        $app = App::getInstance();
        $recursiveFiles = $app->getFilesByKeys( $fileKeys, $config );
        if ( is_wp_error( $recursiveFiles ) ) {
            return $recursiveFiles;
        }
        $selectedFiles = $recursiveFiles['files'] ?? [];
        return $selectedFiles;
    }

    private function fetchShortcode( $id ) {
        $cacheKey = "ccpidb_shortcode_{$id}";
        $cacheResult = wp_cache_get( $cacheKey, "ccpidb_shortcode_{$id}" );
        if ( $cacheResult !== false ) {
            return $cacheResult;
        }
        global $wpdb;
        if ( empty( $id ) ) {
            return new WP_Error(404, __( 'Shortcode ID is required.', 'integrate-dropbox' ));
        }
        $result = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id = %d", $this->tableName, $id ), ARRAY_A );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( empty( $result ) ) {
            return new WP_Error(404, __( 'Shortcode not found.', 'integrate-dropbox' ), [
                'status' => 404,
            ]);
        }
        wp_cache_set(
            $cacheKey,
            $result,
            'ccpidb_shortcodes',
            HOUR_IN_SECONDS
        );
        return $result;
    }

    private function isModuleAutoFetch( $id, $moduleConfig ) {
        if ( empty( $moduleConfig ) ) {
            return false;
        }
        if ( empty( $moduleConfig['autoFetch'] ) ) {
            return false;
        }
        $transientKey = "ccpidb_module_auto_fetch_{$id}";
        $autoFetch = get_transient( $transientKey );
        if ( empty( $autoFetch ) ) {
            $autoFetchInterval = $moduleConfig['autoFetchInterval'] ?? 60;
            set_transient( $transientKey, true, $autoFetchInterval );
            return true;
        }
        return false;
    }

    private function processAndSerializeModuleData( $type, $data ) {
        $processedData = [];
        foreach ( $data as $key => $value ) {
            if ( $key === 'source' ) {
                $fileKyeAndThumbnailKeys = $value['fileKeys'] ?? [];
                $processedData['source']['fileKeys'] = $fileKyeAndThumbnailKeys;
            } else {
                $processedData[$key] = $value;
            }
        }
        return maybe_serialize( $processedData );
    }

    /**
     * Attach thumbnail data to files using thumbnail keys.
     *
     * @param array $files Files list (each item must contain fileKey)
     * @param array $fileKeys Source file keys with optional thumbnailKey
     *
     * @return array
     */
    private function attachThumbnailsToFiles( array $files, array $fileKeys ) : array {
        $availableThumbnail = array_filter( $fileKeys, static fn( $item ) => !empty( $item['thumbnailKey'] ) );
        if ( !$availableThumbnail ) {
            return $files;
        }
        /**
         * Map: thumbnailKey => originalFileKey
         */
        $thumbnailToOriginal = [];
        $thumbnailKeys = [];
        foreach ( $availableThumbnail as $item ) {
            $thumbnailKeys[] = $item['thumbnailKey'];
            $thumbnailToOriginal[$item['thumbnailKey']] = $item['fileKey'];
        }
        $thumbnails = Files::getInstance()->getFileAttributesByKeys( $thumbnailKeys, [
            'fileKey',
            'name',
            'thumbnail',
            'additionalData',
            'extension'
        ] );
        if ( is_wp_error( $thumbnails ) || !$thumbnails ) {
            return $files;
        }
        /**
         * Map: originalFileKey => thumbnail data
         */
        $thumbnailMap = [];
        foreach ( $thumbnails as $thumbnail ) {
            $originalFileKey = $thumbnailToOriginal[$thumbnail['fileKey']] ?? null;
            if ( $originalFileKey ) {
                $thumbnail['basename'] = $thumbnail['additionalData']['basename'] ?? '';
                unset($thumbnail['additionalData']);
                $thumbnailMap[$originalFileKey] = $thumbnail;
            }
        }
        /**
         * Attach thumbnails in a single pass
         */
        foreach ( $files as &$file ) {
            if ( !empty( $thumbnailMap[$file['fileKey']] ) ) {
                $file['thumbnailData'] = $thumbnailMap[$file['fileKey']];
            }
        }
        unset($file);
        // prevent reference leak
        return $files;
    }

}

// phpcs:enable WordPress.DB.DirectDatabaseQuery