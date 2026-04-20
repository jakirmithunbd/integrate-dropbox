<?php

use CodeConfig\IDB\Models\Files;
use CodeConfig\IDB\Models\UserAccess;
use CodeConfig\IDB\Shortcode;
use CodeConfig\IDB\Utils\Helpers;
defined( "ABSPATH" ) || exit( "No direct script access allowed" );
function ccpidb_fs() {
    return \CodeConfig\IDB\ccpidb_fs();
}

if ( !function_exists( "ccpidbGetAccountByKey" ) ) {
    /**
     * Retrieve an account by its key.
     *
     * @param string $key The key of the account to retrieve.
     * @return CodeConfig\IDB\App\Account|WP_Error
     */
    function ccpidbGetAccountByKey(  $key  ) {
        $account = \CodeConfig\IDB\App\Accounts::getInstance()->getAccountByKey( $key );
        return $account;
    }

}
if ( !function_exists( "ccpidbGetExtensionGroups" ) ) {
    /**
     * Retrieves an array of file extensions by the given types.
     *
     * @param array|string $keys An array or string if single type of types to filter by. Valid types are:
     *                           - 'folder'
     *                           - 'document'
     *                           - 'code'
     *                           - 'image'
     *                           - 'audio'
     *                           - 'video'
     *                           - 'archive'
     *                           - 'binary_executable'
     *                           - 'all'
     *
     * @return array An array of file extensions if found, or an empty array if not found.
     */
    function ccpidbGetExtensionGroups(  $keys = []  ) : array {
        if ( is_string( $keys ) ) {
            $keys = [$keys];
        }
        $groups = [
            'folder'            => ['folder'],
            'document'          => [
                'spreadsheet',
                'document',
                'presentation',
                'script',
                'form',
                'drawing',
                'xls',
                'xlsx',
                'doc',
                'docx',
                'ppt',
                'pptx',
                'pdf',
                'txt',
                'csv',
                'rtf',
                'odt',
                'ods',
                'odp',
                'epub',
                'md'
            ],
            'code'              => [
                'js',
                'php',
                'py',
                'java',
                'cs',
                'cpp',
                'c',
                'rb',
                'go',
                'ts',
                'xml',
                'json',
                'yaml',
                'sh'
            ],
            'image'             => [
                'jpg',
                'jpeg',
                'png',
                'gif',
                'webp',
                'svg',
                'bmp',
                'tiff',
                'ico'
            ],
            'audio'             => [
                'mp3',
                'wav',
                'ogg',
                'flac',
                'aac',
                'm4a'
            ],
            'video'             => [
                'mp4',
                'avi',
                'mov',
                'wmv',
                'flv',
                'mkv',
                'webm'
            ],
            'archive'           => [
                'zip',
                'rar',
                'tar',
                'gz',
                '7z',
                'bz2',
                'xz'
            ],
            'binary_executable' => [
                'exe',
                'dll',
                'iso',
                'bin',
                'apk',
                'msi'
            ],
        ];
        if ( empty( $keys ) ) {
            return $groups;
        }
        if ( in_array( 'all', $keys, true ) ) {
            return array_merge( ...array_values( $groups ) );
        }
        // Keep only requested keys
        $filtered = array_intersect_key( $groups, array_flip( $keys ) );
        // Flatten into a single array
        return ( $filtered ? array_merge( ...array_values( $filtered ) ) : [] );
    }

}
if ( !function_exists( "ccpidbGetExtensionByMimeType" ) ) {
    /**
     * Retrieves the file extension associated with a given MIME type.
     *
     * @param string $mimeType The MIME type to retrieve the extension for.
     *
     * @return string The file extension associated with the given MIME type,
     *                or 'unknown' if no extension can be determined.
     */
    function ccpidbGetExtensionByMimeType(  string $mimeType  ) {
        $map = ccpidbGetMimeTypeMap( 'mime2ext' );
        return $map[$mimeType] ?? 'unknown';
    }

}
if ( !function_exists( "ccpidbGetMimeTypeByExtension" ) ) {
    /**
     * Retrieves the MIME type associated with a given file extension.
     *
     * @param string $extension The file extension to retrieve the MIME type for.
     *
     * @return string The MIME type associated with the given extension,
     *                or 'application/octet-stream' if no association can be determined.
     */
    function ccpidbGetMimeTypeByExtension(  string $extension  ) {
        $map = ccpidbGetMimeTypeMap( 'ext2mime' );
        return $map[$extension] ?? 'application/octet-stream';
    }

}
if ( !function_exists( "ccpidbGetMimeTypesByGroup" ) ) {
    /**
     * Retrieves the MIME types associated with a given set of file types.
     *
     * @param array $types The set of file types to retrieve the MIME types for.
     *
     * @return array The array of MIME types associated with the given set of file types.
     */
    function ccpidbGetMimeTypesByGroup(  array $types  ) {
        $extensions = ccpidbGetExtensionGroups( $types );
        $map = ccpidbGetMimeTypeMap( 'ext2mime' );
        $mimeTypes = array_filter( array_map( fn( $ext ) => $map[$ext] ?? null, $extensions ) );
        return array_values( array_unique( $mimeTypes ) );
    }

}
if ( !function_exists( "ccpidbGetMimeTypeMap" ) ) {
    /**
     * Retrieves the MIME type mapping array.
     *
     * The returned array has either MIME types as keys and their associated
     * file extensions as values, or the reverse depending on the value of
     * the $type parameter. If $type is 'mime2ext', the array is flipped
     * so that file extensions are keys and MIME types are values.
     *
     * @param string $type The type of mapping to retrieve. Either 'mime2ext'
     *                     or 'ext2mime'.
     *
     * @return array The MIME type mapping array.
     */
    function ccpidbGetMimeTypeMap(  string $type = 'mime2ext'  ) {
        static $mimeMap = [
            'application/vnd.google-apps.folder'                                        => 'folder',
            'application/vnd.google-apps.spreadsheet'                                   => 'spreadsheet',
            'application/vnd.google-apps.document'                                      => 'document',
            'application/vnd.google-apps.presentation'                                  => 'presentation',
            'application/vnd.google-apps.script'                                        => 'script',
            'application/vnd.google-apps.form'                                          => 'form',
            'application/vnd.google-apps.drawing'                                       => 'drawing',
            'application/vnd.ms-excel'                                                  => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'         => 'xlsx',
            'application/msword'                                                        => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   => 'docx',
            'application/vnd.ms-powerpoint'                                             => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/pdf'                                                           => 'pdf',
            'text/plain'                                                                => 'txt',
            'text/csv'                                                                  => 'csv',
            'image/jpeg'                                                                => 'jpg',
            'image/png'                                                                 => 'png',
            'image/gif'                                                                 => 'gif',
            'image/webp'                                                                => 'webp',
            'image/svg+xml'                                                             => 'svg',
            'application/zip'                                                           => 'zip',
            'application/x-rar-compressed'                                              => 'rar',
            'application/x-tar'                                                         => 'tar',
            'application/gzip'                                                          => 'gz',
            'audio/mpeg'                                                                => 'mp3',
            'audio/wav'                                                                 => 'wav',
            'video/mp4'                                                                 => 'mp4',
            'video/x-msvideo'                                                           => 'avi',
        ];
        return ( $type === 'ext2mime' ? array_flip( $mimeMap ) : $mimeMap );
    }

}
if ( !function_exists( "ccpidbGetDefaultSettings" ) ) {
    function ccpidbGetDefaultSettings() : array {
        $settings = [
            "accounts"                      => [
                "connectionType" => "manual",
                "appKey"         => "",
                "appSecret"      => "",
                "redirectUri"    => Helpers::redirectUrl(),
            ],
            'advanced'                      => [
                'manageSharingPermissions' => true,
                'secureVideoPlayback'      => false,
                'allowDotExtension'        => true,
                "deleteDataOnUninstall"    => false,
            ],
            'appearance'                    => [
                "preloader"    => "1",
                "primaryColor" => "#0061fe",
                "customCSS"    => "",
            ],
            'userAccess'                    => [[
                'id'       => '0',
                'base'     => "role_base",
                'role'     => [],
                'user'     => [],
                'folders'  => [],
                'settings' => [],
            ]],
            'integrations'                  => [
                "activeIntegrations" => [
                    'mediaLibrary',
                    'gutenberg',
                    'elementor',
                    'contactForm7'
                ],
                'mediaLibrary'       => [
                    'folders'         => [],
                    'deleteCloudFile' => false,
                    "mlHoverPreview"  => false,
                ],
            ],
            'synchronization'               => [
                'enableSync'  => false,
                'folders'     => [],
                'timer'       => "custom",
                'customTimer' => 120,
            ],
            'tools'                         => [
                "autoSave" => false,
            ],
            "createFolderOnRegistration"    => false,
            "privateFolderInAdminDashboard" => false,
            "excludeIncludeFolder"          => false,
            "isEditing"                     => false,
            "draft"                         => null,
            "menu"                          => "Accounts",
        ];
        return $settings;
    }

}
if ( !function_exists( "ccpidbGetModuleDefaultData" ) ) {
    /**
     * Get default module data by type.
     *
     * @param string $type Module type. Allowed:
     *                     'file-browser', 'file-uploader', 'media-player',
     *                     'gallery', 'search-box', 'file-list',
     *                     'slider-carousel', 'embed-documents'
     *
     * @return array Module configuration array
     */
    function ccpidbGetModuleDefaultData(  string $type  ) : array {
        if ( !in_array( $type, Shortcode::getModulesList(), true ) ) {
            return [];
        }
        $data = [
            'id'          => 'new',
            'status'      => 'active',
            'integration' => null,
            'data'        => [
                'source'      => [
                    'fileKeys'      => [],
                    'selectedFiles' => [],
                ],
                'filter'      => [
                    'extension' => [
                        'include' => [],
                        'exclude' => [],
                        'all'     => false,
                    ],
                    'name'      => [
                        'include' => '',
                        'exclude' => '',
                        'all'     => false,
                        'applyTo' => [
                            'files'   => true,
                            'folders' => true,
                        ],
                    ],
                ],
                'advanced'    => [],
                'permissions' => [
                    'passwordProtect' => [
                        'enable'   => false,
                        'password' => '',
                    ],
                    'displayFor'      => [
                        'whoCanViewModule'        => 'everyone',
                        'loggedInUserType'        => 'users',
                        'displayFor'              => [],
                        'showAccessDeniedMessage' => true,
                        'accessDeniedMessage'     => 'You do not have access to this module.',
                    ],
                ],
            ],
        ];
        $advancedDefaults = [
            'width'               => [
                'value' => 100,
                'unit'  => '%',
            ],
            'height'              => [
                'value' => 100,
                'unit'  => 'auto',
            ],
            'theme'               => 'light',
            'borderBoxVisibility' => false,
            'files'               => [
                'loadingType' => 'load_more',
                'perPage'     => 20,
            ],
            'autoFetch'           => [
                'status'   => false,
                'interval' => 60,
            ],
            'sort'                => [
                'orderBy' => 'name',
                'order'   => 'ASC',
            ],
        ];
        $permissionBase = [
            'userAccess'       => 'everyone',
            'loggedInUserType' => 'users',
            'displayFor'       => [],
        ];
        $permissions = [
            'newFolder'     => $permissionBase + [
                'enable' => true,
            ],
            'upload'        => $permissionBase + [
                'enable'       => true,
                'folderUpload' => true,
            ],
            'preview'       => $permissionBase + [
                'enable'           => false,
                'inline'           => true,
                'popOut'           => false,
                'previewThumbnail' => true,
            ],
            'openInDropbox' => $permissionBase + [
                'enable' => false,
            ],
            'rename'        => $permissionBase + [
                'enable' => false,
            ],
            'download'      => $permissionBase + [
                'enable'           => false,
                'folderDownload'   => false,
                'multipleDownload' => false,
            ],
            'copy'          => $permissionBase + [
                'enable' => true,
            ],
            'move'          => $permissionBase + [
                'enable' => true,
            ],
            'delete'        => $permissionBase + [
                'enable'              => false,
                'isMigrateAttachment' => false,
            ],
            'viewDetails'   => $permissionBase + [
                'enable' => false,
            ],
            'share'         => $permissionBase + [
                'enable' => false,
            ],
            'search'        => $permissionBase + [
                'enable'         => true,
                'searchLocation' => [
                    'cache'  => true,
                    'server' => true,
                ],
                'searchScope'    => [
                    'current' => true,
                    'global'  => true,
                ],
            ],
        ];
        $notificationList = [
            'newFolder',
            'preview',
            'rename',
            'upload',
            'download',
            'shareLink',
            'viewShareFile',
            'copy',
            'move',
            'delete'
        ];
        $notifications = [
            'enable'          => [],
            'emailRecipients' => '',
            'skipCurrentUser' => false,
        ];
        foreach ( $notificationList as $action ) {
            $notifications[$action] = false;
        }
        $uploadFilter = [
            'maxSize'  => 0,
            'minSize'  => 0,
            'maxFiles' => 0,
        ];
        $modules = [
            'embed-documents' => [
                'title'             => 'Embed Documents',
                'advancedKey'       => 'embedDocuments',
                'embedDocuments'    => [
                    "showFileName"       => false,
                    "directMediaDisplay" => false,
                    'width'              => [
                        'value' => 100,
                        'unit'  => '%',
                    ],
                    'height'             => [
                        'value' => 600,
                        'unit'  => 'px',
                    ],
                    "allowPopOut"        => true,
                ],
                'advancedOverrides' => [
                    'files' => [
                        'perPage' => 2,
                    ],
                ],
            ],
            'gallery'         => [
                'title'         => 'Gallery',
                'advancedKey'   => 'gallery',
                'gallery'       => [
                    'layout'                    => "grid",
                    'rowHeight'                 => 200,
                    "columnsDevice"             => "desktop",
                    'columns'                   => [
                        'desktop' => 4,
                        'tablet'  => 3,
                        'mobile'  => 2,
                    ],
                    'aspectRatio'               => '1:1',
                    'thumbnailSpacing'          => 10,
                    'thumbnailQuality'          => "medium",
                    'thumbnailView'             => "rounded",
                    'showOverlay'               => false,
                    'overlayDisplayType'        => 'hover',
                    'overlayDisplayTitle'       => false,
                    'overlayDisplayDescription' => false,
                    'overlayDisplaySize'        => false,
                ],
                'permissions'   => ['download', 'preview'],
                'notifications' => [
                    'enable',
                    'emailRecipients',
                    'skipCurrentUser',
                    'download'
                ],
            ],
            'search-box'      => [
                'title'         => 'Search Box',
                'advancedKey'   => 'searchBox',
                'searchBox'     => [
                    "browserView"      => "grid",
                    "showLastModified" => false,
                    "searchBoxText"    => "Search for files & folders...",
                ],
                'permissions'   => ['download', 'preview'],
                'notifications' => [
                    'enable',
                    'emailRecipients',
                    'skipCurrentUser',
                    'download'
                ],
            ],
            'file-list'       => [
                'title'         => 'File List',
                'advancedKey'   => 'fileList',
                'fileList'      => [
                    "viewButtonText"          => "View",
                    "viewBackgroundColor"     => "#0061fe",
                    "viewTextColor"           => "#ffffff",
                    "viewBorderRadius"        => 10,
                    "viewButtonSize"          => "medium",
                    "downloadButton"          => false,
                    "downloadButtonText"      => "Download",
                    "downloadBackgroundColor" => "#0061fe",
                    "downloadTextColor"       => "#ffffff",
                    "downloadBorderRadius"    => 10,
                    "downloadButtonSize"      => "medium",
                    "columnsDevice"           => "desktop",
                    "columns"                 => [
                        'desktop' => 3,
                        'tablet'  => 2,
                        'mobile'  => 1,
                    ],
                    "openInNewTab"            => false,
                    "showFileSize"            => true,
                    "showFileExtension"       => false,
                    "showTimeStamp"           => true,
                ],
                'permissions'   => ['download', 'preview'],
                'notifications' => [
                    'enable',
                    'emailRecipients',
                    'skipCurrentUser',
                    'download'
                ],
            ],
        ];
        $module_list = [
            'gallery',
            'embed-documents',
            'search-box',
            'file-list'
        ];
        if ( !in_array( $type, $module_list, true ) ) {
            return $data;
        }
        if ( !isset( $modules[$type] ) ) {
            return $data;
        }
        $module = $modules[$type];
        $data['type'] = $type;
        $data['title'] = $module['title'];
        if ( !empty( $module['filters'] ) ) {
            foreach ( $module['filters'] as $filter ) {
                $data['data']['filter'][$filter] = $uploadFilter;
            }
        }
        if ( !empty( $module['overridePermissions'] ) ) {
            foreach ( $module['overridePermissions'] as $permKey => $permValues ) {
                if ( isset( $permissions[$permKey] ) ) {
                    $permissions[$permKey] = $permValues;
                }
            }
        }
        if ( !empty( $module['permissions'] ) ) {
            foreach ( $module['permissions'] as $perm ) {
                $data['data']['permissions'][$perm] = $permissions[$perm];
            }
        }
        if ( !empty( $module['notifications'] ) ) {
            foreach ( $module['notifications'] as $notify ) {
                $data['data']['notifications'][$notify] = $notifications[$notify];
            }
        }
        $advanced = $advancedDefaults;
        if ( !empty( $module['excludeAdvanced'] ) ) {
            foreach ( $module['excludeAdvanced'] as $advKey ) {
                unset($advanced[$advKey]);
            }
        }
        if ( !empty( $module['advancedOverrides'] ) ) {
            $advanced = ccpidbDeepMergeWithUnset( $advanced, $module['advancedOverrides'] );
        }
        $data['data']['advanced'] = array_merge( $advanced, ( $module['advancedKey'] && isset( $module[$module['advancedKey']] ) ? [
            $module['advancedKey'] => $module[$module['advancedKey']],
        ] : [] ) );
        return $data;
    }

}
if ( !function_exists( "ccpidbDeepMergeWithUnset" ) ) {
    /**
     * Recursively merges two arrays.
     *
     * @param array $base The base array.
     * @param array $override The array with overriding values.
     *
     * @return array The merged array.
     */
    function ccpidbDeepMergeWithUnset(  array $base, array $override  ) : array {
        foreach ( $override as $key => $value ) {
            if ( $value === CCPIDB_UNSET ) {
                unset($base[$key]);
                continue;
            }
            if ( is_array( $value ) && isset( $base[$key] ) && is_array( $base[$key] ) ) {
                $base[$key] = ccpidbDeepMergeWithUnset( $base[$key], $value );
                continue;
            }
            $base[$key] = $value;
        }
        return $base;
    }

}
if ( !function_exists( "ccpidbGetShortcodeTypesSchema" ) ) {
    /**
     * Retrieve the schema for the shortcode types.
     *
     * @param string|null $key The key of the shortcode type to retrieve.
     *
     * @return array The schema for the shortcode types.
     */
    function ccpidbGetShortcodeTypesSchema(  $key = null  ) {
        $defaultSchema = [
            'id'          => 'integer',
            'title'       => 'string',
            'status'      => 'string',
            'type'        => 'string',
            'integration' => 'string|null',
            'createdAt'   => 'string',
            'data'        => [
                'source'        => [
                    'fileKeys'    => 'array',
                    'hasMore'     => 'boolean',
                    'totalCount'  => 'integer',
                    'currentPage' => 'integer',
                    'perPage'     => 'integer',
                    'nextPage'    => 'integer|null',
                    'totalPages'  => 'integer',
                ],
                'filter'        => 'array',
                'notifications' => 'array',
                'permissions'   => 'array',
            ],
        ];
        if ( current_user_can( 'manage_options' ) ) {
            $defaultSchema['locations'] = 'array';
        }
        $defaultAdvanced = [
            'width'               => "array",
            'height'              => 'array',
            'theme'               => 'string',
            'files'               => 'array',
            'borderBoxVisibility' => 'boolean',
            'autoFetch'           => 'array',
            'sort'                => 'array',
        ];
        $gallery = $defaultSchema;
        $gallery['data']['source']['files[]'] = [
            'fileKey'         => 'string',
            'name'            => 'string',
            'hasOwnThumbnail' => 'string',
            'thumbnail'       => 'string',
            'icon'            => 'string',
            'extension'       => 'string',
            'mimeType'        => 'string',
            'media'           => 'array',
            'additionalData'  => 'array',
            'thumbnailRatio'  => 'string',
        ];
        $gallery['data']['advanced'] = $defaultAdvanced;
        $gallery['data']['advanced']['gallery'] = 'array';
        $embedDocuments = $defaultSchema;
        $embedDocuments['data']['source']['files[]'] = [
            'fileKey'         => 'string',
            'name'            => 'string',
            'hasOwnThumbnail' => 'string',
            'thumbnail'       => 'string',
            'icon'            => 'string',
            'caption'         => 'string',
            'extension'       => 'string',
            'additionalData'  => 'array',
        ];
        $embedDocuments['data']['advanced'] = $defaultAdvanced;
        $embedDocuments['data']['advanced']['embedDocuments'] = 'array';
        $searchBox = $defaultSchema;
        $searchBox['data']['source']['files[]'] = [
            'fileKey'         => 'string',
            'name'            => 'string',
            'extension'       => 'string',
            'mimeType'        => 'string',
            'hasOwnThumbnail' => 'string',
            'thumbnail'       => 'string',
            'icon'            => 'string',
            'size'            => 'integer',
            'lastEdited'      => 'string',
            'additionalData'  => 'array',
        ];
        $searchBox['data']['source']['breadcrumbs[]'] = [
            'fileKey' => 'string',
            'name'    => 'string',
        ];
        $searchBox['data']['advanced'] = $defaultAdvanced;
        $searchBox['data']['advanced']['searchBox'] = 'array';
        $fileList = $defaultSchema;
        $fileList['data']['source']['files[]'] = [
            'fileKey'         => 'string',
            'name'            => 'string',
            'extension'       => 'string',
            'mimeType'        => 'string',
            'hasOwnThumbnail' => 'string',
            'thumbnail'       => 'string',
            'icon'            => 'string',
            'size'            => 'integer',
            'createdAt'       => 'string',
            'additionalData'  => 'array',
        ];
        $fileList['data']['advanced'] = $defaultAdvanced;
        $fileList['data']['advanced']['fileList'] = 'array';
        $schema = [
            'gallery'         => $gallery,
            'embed-documents' => $embedDocuments,
            'search-box'      => $searchBox,
            'file-list'       => $fileList,
        ];
        if ( !empty( $key ) ) {
            if ( !isset( $schema[$key] ) ) {
                return $gallery;
            }
            return $schema[$key];
        }
        return $schema;
    }

}
if ( !function_exists( "ccpidbGetTablesDefinitions" ) ) {
    function ccpidbGetTablesDefinitions(  $key = null  ) {
        global $wpdb;
        $charsetCollate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix;
        $tables = [
            'shortcodes'  => "CREATE TABLE IF NOT EXISTS `{$prefix}ccpidb_shortcodes` (\n                `id` INT AUTO_INCREMENT,\n                `title` VARCHAR(120) DEFAULT NULL,\n                `type` VARCHAR(20) NOT NULL,\n                `status` VARCHAR(10) DEFAULT 'active',\n                `integration` VARCHAR(60) DEFAULT NULL,\n                `data` LONGTEXT DEFAULT NULL,\n                `locations` LONGTEXT DEFAULT NULL,\n                `createdAt` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',\n                `updatedAt` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',\n                PRIMARY KEY (`id`)\n            ) {$charsetCollate};",
            'user_access' => "CREATE TABLE IF NOT EXISTS `{$prefix}ccpidb_user_access` (\n                `id` INT AUTO_INCREMENT,\n                `type` TEXT NOT NULL,\n                `value` TEXT NOT NULL,\n                `folders` LONGTEXT DEFAULT NULL,\n                `pages` LONGTEXT DEFAULT NULL,\n                `createdAt` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',\n                `updatedAt` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',\n                PRIMARY KEY (`id`)\n            ) {$charsetCollate};",
            'files'       => "CREATE TABLE IF NOT EXISTS `{$prefix}ccpidb_files` (\n                `id` INT AUTO_INCREMENT,\n                `fileId` VARCHAR(120) NOT NULL,\n                `fileKey` VARCHAR(120) NOT NULL,\n                `path` TEXT NOT NULL,\n                `name` TEXT NULL,\n                `size` BIGINT NULL,\n                `parent` TEXT,\n                `accountId` TEXT NOT NULL,\n                `mimeType` VARCHAR(255) NOT NULL,\n                `extension` VARCHAR(60) DEFAULT NULL,\n                `thumbnail` VARCHAR(255) NULL,\n                `thumbnailRatio` VARCHAR(60) NULL, \n                `description` LONGTEXT NULL,\n                `metaData` LONGTEXT NULL,\n                `sharedLink` LONGTEXT NULL,\n                `isDir` TINYINT(1) DEFAULT 0,\n                `permissions` LONGTEXT DEFAULT NULL,\n                `hasOwnThumbnail` TINYINT(1) DEFAULT 0,\n                `icon` LONGTEXT DEFAULT NULL,\n                `additionalData` LONGTEXT,\n                `createdAt` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',\n                `updatedAt` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',\n                PRIMARY KEY (`id`)\n            ) {$charsetCollate};",
            'accounts'    => "CREATE TABLE IF NOT EXISTS `{$prefix}ccpidb_accounts` (\n                `id` VARCHAR(120) NOT NULL,\n                `accountKey` TEXT NOT NULL,\n                `name` TEXT NOT NULL,\n                `email` TEXT NOT NULL,\n                `photo` TEXT NOT NULL,\n                `storage` LONGTEXT DEFAULT NULL,\n                `lost` TINYINT(1) DEFAULT 1,\n                `rootInfo` LONGTEXT NOT NULL,\n                `userId` INT NOT NULL,\n                `active` TINYINT(1) DEFAULT 0,\n                `tokens` LONGTEXT NULL,\n                `emailVerified` TINYINT(1) DEFAULT 0,\n                `disabled` TINYINT(1) DEFAULT 0,\n                `country` VARCHAR(10) DEFAULT NULL,\n                `locale` VARCHAR(10) DEFAULT NULL,\n                `type` VARCHAR(50) DEFAULT NULL,\n                `referralLink` TEXT DEFAULT NULL,\n                `isPaired` TINYINT(1) DEFAULT 0,\n                `isTeam` TINYINT(1) DEFAULT 0,\n                `createdAt` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',\n                `updatedAt` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',\n                PRIMARY KEY (`id`),\n                UNIQUE KEY `unique_key` (`accountKey`(191))\n            ) {$charsetCollate};",
            'logs'        => "CREATE TABLE IF NOT EXISTS `{$prefix}ccpidb_logs` (\n                `id` INT AUTO_INCREMENT,\n                `moduleId` INT DEFAULT NULL,\n                `userId` INT DEFAULT NULL,\n                `fileKey` TEXT DEFAULT NULL,\n                `fileName` TEXT DEFAULT NULL,\n                `page` TEXT DEFAULT NULL,\n                `data` LONGTEXT DEFAULT NULL,\n                `type` TEXT NOT NULL,\n                `title` TEXT NOT NULL,\n                `status` TEXT NOT NULL,\n                `description` TEXT DEFAULT NULL,\n                `createdAt` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',\n                `updatedAt` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',\n                PRIMARY KEY (`id`) \n            ) {$charsetCollate};",
        ];
        if ( $key !== null && isset( $tables[$key] ) ) {
            return $tables[$key];
        }
        return array_values( $tables );
    }

}
if ( !function_exists( "ccpidbGetAllowedModuleExtensions" ) ) {
    function ccpidbGetAllowedModuleExtensions(  string $type  ) {
        $typeGroups = [
            'gallery'         => ccpidbGetExtensionGroups( 'image' ),
            'media-player'    => ccpidbGetExtensionGroups( ['audio', 'video'] ),
            'slider-carousel' => ccpidbGetExtensionGroups( ['image'] ),
            'all'             => ccpidbGetExtensionGroups( 'all' ),
            'embed-documents' => ccpidbGetExtensionGroups( [
                'document',
                'pdf',
                'spreadsheet',
                'presentation'
            ] ),
        ];
        return $typeGroups[$type] ?? $typeGroups['all'];
    }

}
if ( !function_exists( "ccpidbDeleteAllAttachments" ) ) {
    function ccpidbDeleteAllAttachments() {
        $page = 1;
        do {
            $attachments = get_posts( [
                'post_type'      => 'attachment',
                'posts_per_page' => 100,
                'paged'          => $page,
                'meta_query'     => [[
                    'fileKey' => '_ccpidb_media_folder_key',
                    'compare' => 'EXISTS',
                ]],
            ] );
            foreach ( $attachments as $attachment ) {
                wp_delete_attachment( $attachment->ID, true );
            }
            $page++;
        } while ( count( $attachments ) > 0 );
        return true;
    }

}
if ( !function_exists( "ccpidbGetTemplate" ) ) {
    function ccpidbGetTemplate(  $slug, $args = [], $name = null  ) {
        $template = locate_template( "{$slug}-{$name}.php" );
        if ( !$template ) {
            $template = CCPIDB_PATH . "templates/{$slug}.php";
            if ( $name ) {
                $template = CCPIDB_PATH . "templates/{$slug}-{$name}.php";
            }
        }
        if ( file_exists( $template ) ) {
            if ( !empty( $args ) && is_array( $args ) ) {
                extract( $args );
            }
            include $template;
        }
    }

}
if ( !function_exists( "ccpidbFormatBytes" ) ) {
    function ccpidbFormatBytes(  int $bytes, int $decimals = 2  ) : string {
        if ( $bytes < 0 ) {
            return "0 B";
        }
        $units = [
            'B',
            'KB',
            'MB',
            'GB',
            'TB',
            'PB'
        ];
        $factor = floor( (strlen( (string) $bytes ) - 1) / 3 );
        return sprintf( "%.{$decimals}f %s", $bytes / pow( 1024, $factor ), $units[$factor] );
    }

}
if ( !function_exists( "ccpidbParseSizeToBytes" ) ) {
    function ccpidbParseSizeToBytes(  string $size  ) : int {
        $units = [
            'B',
            'KB',
            'MB',
            'GB',
            'TB',
            'PB'
        ];
        // Trim and normalize input
        $size = trim( $size );
        $pattern = '/^([\\d.]+)\\s*([KMGTPE]?B)$/i';
        if ( !preg_match( $pattern, $size, $matches ) ) {
            return 0;
            // invalid format
        }
        $value = (float) $matches[1];
        $unit = strtoupper( $matches[2] );
        $factor = array_search( $unit, $units, true );
        return (int) round( $value * pow( 1024, $factor ) );
    }

}
if ( !function_exists( "ccpidbGenerateKey" ) ) {
    function ccpidbGenerateKey(  $fileId, $accountId  ) {
        return md5( "{$fileId}-{$accountId}" );
    }

}
if ( !function_exists( "ccpidbSizeToString" ) ) {
    /**
     * Convert a size identifier to a Dropbox thumbnail size string.
     *
     * @param string $size The size identifier. Valid values are:
     *                     - 'full': Original size (no resizing).
     *                     - 'thumbnail': 150x150 pixels.
     *                     - 'medium': 300x300 pixels.
     *                     - 'large': 1024x1024 pixels.
     *                     - Custom size in the format 'WIDTHxHEIGHT' (e.g., '400x300').
     *
     * @return string The corresponding Dropbox thumbnail size string.
     *                Returns an empty string for 'full' or invalid inputs.
     */
    function ccpidbSizeToString(  $size  ) {
        $map = [
            'full'      => '',
            'thumbnail' => 'w150-h150-c-nu',
            'medium'    => 'w300-h300-c-nu',
            'large'     => 'w1024-h1024-c-nu',
        ];
        if ( isset( $map[$size] ) ) {
            return $map[$size];
        }
        if ( preg_match( '/^(\\d+)x(\\d+)$/', $size, $m ) ) {
            $w = (int) $m[1];
            $h = (int) $m[2];
            return "w{$w}-h{$h}-c-nu";
        }
        return '';
    }

}
if ( !function_exists( "getDuplicateItems" ) ) {
    /**
     * Get duplicate items from an array.
     *
     * This function takes an array as input and returns an array of items
     * that appear more than once in the input array.
     *
     * @param array $array The input array to check for duplicate items.
     *
     * @return array An array of duplicate items found in the input array.
     */
    function ccpidbGetDuplicateItems(  array $array  ) : array {
        return array_keys( array_filter( array_count_values( $array ), fn( $count ) => $count > 1 ) );
    }

}
if ( !function_exists( "ccpidbTitleToUrlSlug" ) ) {
    function ccpidbTitleToUrlSlug(  $filename  ) {
        if ( $filename === '' ) {
            return 'unknown-file';
        }
        if ( class_exists( 'Normalizer' ) ) {
            $filename = Normalizer::normalize( $filename, Normalizer::FORM_C );
        }
        $filename = preg_replace( '/[\\/\\\\\\?\\<\\>\\:\\*\\|"\\`~!@#$%^&()+={}\\[\\];\',]+/u', '', $filename );
        $filename = preg_replace( '/\\s+/u', '-', $filename );
        $filename = preg_replace( '/-+/u', '-', $filename );
        $filename = preg_replace( '/\\.{2,}/u', '.', $filename );
        $filename = trim( $filename, ".-_" );
        $filename = mb_strtolower( $filename, 'UTF-8' );
        return $filename;
    }

}
/**
 * Generate a secure and optimized attachment URL for CCPIDB.
 *
 * @param string $key Unique file key.
 * @param string $name File name (without extension).
 * @param string $size Image size (default: full).
 * @param string $extension File extension (default: jpg).
 *
 * @return string Sanitized attachment URL.
 */
function ccpidbGetUrl(
    $action,
    $key,
    $name = 'unknown',
    $size = 'lg',
    $ext = 'webp',
    $referer = null
) {
    if ( empty( $key ) ) {
        return '';
    }
    $ext = ( empty( $ext ) ? 'webp' : strtolower( sanitize_text_field( $ext ) ) );
    $name = str_replace( ".{$ext}", '', $name );
    $allowed_actions = [
        'attachment',
        'thumbnail',
        'stream',
        'preview',
        'download',
        'share'
    ];
    if ( !in_array( $action, $allowed_actions, true ) ) {
        return '';
    }
    $action = sanitize_key( $action );
    $referer = ( $referer !== null ? $referer : null );
    $key = sanitize_key( $key );
    $name = ccpidbTitleToUrlSlug( $name );
    $size = strtolower( sanitize_text_field( $size ?? '' ) );
    $allowSizes = array_keys( ccpidbGetAvailableThumbnailSizes() );
    $allowed_sizes = apply_filters( 'ccpidb_allowed_sizes', $allowSizes );
    if ( !in_array( $size, $allowed_sizes, true ) ) {
        $size = null;
    }
    if ( $referer !== null ) {
        $action .= "-{$referer}";
    }
    if ( $size ) {
        $name .= "-{$size}";
    }
    $ext = ( empty( $ext ) ? 'webp' : strtolower( sanitize_text_field( $ext ) ) );
    $allowDotExtension = Helpers::getSetting( 'advanced.allowDotExtension', true );
    if ( !$allowDotExtension ) {
        return home_url( sprintf(
            '/ccpidb/%s/%s/%s/%s',
            $action,
            $key,
            $name,
            $ext
        ) );
    }
    if ( $action === 'attachment' ) {
        return home_url( sprintf(
            '/ccpidb/%s/%s/%s.%s',
            $action,
            $key,
            $name,
            $ext
        ) );
    } else {
        return home_url( sprintf(
            '/ccpidb/%s/%s/%s/%s',
            $action,
            $key,
            $name,
            $ext
        ) );
    }
}

function ccpidbGetFreeMemoryAvailable() {
    if ( function_exists( 'memory_get_usage' ) && function_exists( 'memory_get_peak_usage' ) ) {
        $memory_limit = ini_get( 'memory_limit' );
        if ( $memory_limit === false ) {
            return null;
        }
        $memory_limit = trim( $memory_limit );
        $last = strtolower( $memory_limit[strlen( $memory_limit ) - 1] );
        $memory_limit = (int) $memory_limit;
        switch ( $last ) {
            case 'g':
                $memory_limit *= 1024;
            // no break
            case 'm':
                $memory_limit *= 1024;
            // no break
            case 'k':
                $memory_limit *= 1024;
        }
        $used_memory = memory_get_usage( true );
        $free_memory = $memory_limit - $used_memory;
        return ( $free_memory > 0 ? $free_memory : 0 );
    }
    return null;
}

function ccpidbGetModules(  $type = null  ) {
    $modules = [
        [
            "id"          => "file-browser",
            "title"       => "File Browser",
            "description" => "Allow users to browse selected Dropbox files and folders directly on your site.",
            "icon"        => "folder",
            "isPro"       => true,
            "dependency"  => [
                'js' => ['wp-plupload'],
            ],
        ],
        [
            "id"          => "file-uploader",
            "title"       => "File Uploader",
            "description" => "Allow users to upload files directly from their Dropbox.",
            "icon"        => "cloud_upload",
            "isPro"       => true,
            "isNew"       => true,
            "dependency"  => [
                'js' => ['wp-plupload'],
            ],
        ],
        [
            "id"          => "media-player",
            "title"       => "Media Player",
            "description" => "Allow users to play audio and video files from their Dropbox.",
            "icon"        => "stock_media",
            "isNew"       => true,
            "isPro"       => true,
            "dependency"  => [
                'js' => ['wp-mediaelement', 'mediaelement'],
            ],
        ],
        [
            "id"          => "gallery",
            "title"       => "Image Gallery",
            "description" => "Showcase images from Dropbox in a visually appealing gallery format.",
            "icon"        => "imagesmode",
        ],
        [
            "id"          => "slider-carousel",
            "title"       => "Slider Carousel",
            "description" => "Display Dropbox images in an interactive slider or carousel format.",
            "icon"        => "slideshow",
            "isPro"       => true,
            "isNew"       => true,
        ],
        [
            "id"          => "embed-documents",
            "title"       => "Embed Documents",
            "description" => "Easily embed Google Docs, Sheets, and Slides into your website securely.",
            "icon"        => "text_compare",
            "isNew"       => true,
        ],
        [
            "id"          => "search-box",
            "title"       => "Search Box",
            "description" => "Enable users to search and find specific files in your Dropbox.",
            "icon"        => "feature_search",
            "isNew"       => true,
        ],
        [
            "id"          => "file-list",
            "title"       => "File List",
            "description" => "Display a simple list of files from your Dropbox with download links.",
            "icon"        => "event_list",
            "isNew"       => true,
        ]
    ];
    if ( empty( $type ) ) {
        return $modules;
    }
    if ( $type === 'free' ) {
        return array_filter( $modules, function ( $module ) {
            return empty( $module['isPro'] );
        } );
    } elseif ( $type === 'pro' ) {
        return array_filter( $modules, function ( $module ) {
            return !empty( $module['isPro'] );
        } );
    } elseif ( $type === 'new' ) {
        return array_filter( $modules, function ( $module ) {
            return !empty( $module['isNew'] );
        } );
    } elseif ( $type === 'hot' ) {
        return array_filter( $modules, function ( $module ) {
            return !empty( $module['isHot'] );
        } );
    } else {
        return $modules;
    }
}

// Dropbox codes start here
function ccpidbCleanFolderPath(  $path  ) {
    if ( str_starts_with( $path, 'id:' ) ) {
        $parts = explode( '/', $path );
        $parts[0] = ccpidbGetPathById( $parts[0] );
        $path = implode( '/', $parts );
    }
    $path = html_entity_decode( $path );
    $path = str_replace( [
        '?',
        '<',
        '>',
        ':',
        '"',
        '*',
        '|'
    ], '', $path );
    $path = preg_replace( '#[/\\\\]+#', '/', $path );
    $path = trim( $path, '/' );
    $segments = array_map( 'rtrim', explode( '/', $path ) );
    $clean_path = implode( '/', $segments );
    return ( $clean_path ? "/{$clean_path}" : '' );
}

function ccpidbGetPathById(  $fileId  ) {
    if ( !str_starts_with( $fileId, 'id:' ) ) {
        return $fileId;
    }
    $path = Files::getInstance()->getPathById( $fileId );
    return $path;
}

if ( !function_exists( 'ccpidbGetDropboxFileSupport' ) ) {
    /**
     * Get Dropbox-supported preview and thumbnail file extensions.
     * Also checks if a specific extension supports preview or thumbnail.
     *
     * @param string|null $extension Optional. File extension (without dot).
     * @param string|null $type Optional. 'preview' or 'thumbnail' to check support type.
     *
     * @return array|bool Array of supported extensions if no params, or bool if checking.
     */
    function ccpidbGetDropboxFileSupport(  $extension = null, $type = null  ) {
        // Supported for Dropbox Preview
        $preview_supported = [
            'csv',
            'pdf',
            'txt',
            'ai',
            'eps',
            'odp',
            'odt',
            'doc',
            'docx',
            'docm',
            'ppt',
            'pps',
            'ppsx',
            'ppsm',
            'pptx',
            'pptm',
            'xls',
            'xlsx',
            'xlsm',
            'rtf',
            'jpg',
            'jpeg',
            'gif',
            'png',
            'webp',
            'mp4',
            'm4v',
            'ogg',
            'ogv',
            'webmv',
            'mp3',
            'm4a',
            'ogg',
            'oga',
            'wav',
            'flac',
            'paper',
            'gdoc',
            'gslides',
            'gsheet',
            'mov',
            'mkv',
            'webm',
            'svg'
        ];
        // Supported for Dropbox Thumbnail
        $thumbnail_supported = [
            'csv',
            'doc',
            'docm',
            'docx',
            'ods',
            'odt',
            'pdf',
            'rtf',
            'xls',
            'xlsm',
            'xlsx',
            'odp',
            'pps',
            'ppsm',
            'ppsx',
            'ppt',
            'pptm',
            'pptx',
            '3fr',
            'ai',
            'arw',
            'bmp',
            'cr2',
            'crw',
            'dcs',
            'dcr',
            'dng',
            'eps',
            'erf',
            'gif',
            'heic',
            'jpg',
            'jpeg',
            'kdc',
            'mef',
            'mos',
            'mrw',
            'nef',
            'nrw',
            'orf',
            'pef',
            'png',
            'psd',
            'r3d',
            'raf',
            'rw2',
            'rwl',
            'sketch',
            'sr2',
            'svg',
            'svgz',
            'tif',
            'tiff',
            'x3f',
            '3gp',
            '3gpp',
            '3gpp2',
            'asf',
            'avi',
            'dv',
            'flv',
            'm2t',
            'm4v',
            'mkv',
            'mov',
            'webm',
            'mp4',
            'mpeg',
            'mpg',
            'mts',
            'oggtheora',
            'ogv',
            'rm',
            'ts',
            'vob',
            'webm',
            'wmv',
            'paper',
            'webp'
        ];
        if ( $extension === null ) {
            return [
                'preview'   => $preview_supported,
                'thumbnail' => $thumbnail_supported,
            ];
        }
        $extension = strtolower( sanitize_text_field( wp_strip_all_tags( $extension ) ) );
        if ( 'preview' === $type ) {
            return in_array( $extension, $preview_supported, true );
        } elseif ( 'thumbnail' === $type ) {
            return in_array( $extension, $thumbnail_supported, true );
        }
        return [
            'preview'   => in_array( $extension, $preview_supported, true ),
            'thumbnail' => in_array( $extension, $thumbnail_supported, true ),
        ];
    }

}
if ( !function_exists( "ccpidbGetThumbnailSize" ) ) {
    function ccpidbGetThumbnailSize(  $size = 'md'  ) {
        $map = ccpidbGetAvailableThumbnailSizes();
        return $map[$size] ?? '128x128';
    }

}
if ( !function_exists( "ccpidbGetAvailableThumbnailSizes" ) ) {
    function ccpidbGetAvailableThumbnailSizes() {
        return [
            'xs'  => 'w32h32',
            'sm'  => 'w64h64',
            'md'  => 'w128h128',
            'lg'  => 'w256h256',
            'xl'  => 'w480h320',
            '2xl' => 'w640h480',
            '3xl' => 'w960h640',
            '4xl' => 'w1024h768',
            '5xl' => 'w2048h1536',
        ];
    }

}
function ccpidbGetCurrentUserAccess() {
    if ( !function_exists( 'is_user_logged_in' ) || !function_exists( 'wp_get_current_user' ) ) {
        return false;
    }
    if ( !is_user_logged_in() ) {
        return false;
    }
    $currentUser = wp_get_current_user();
    if ( !$currentUser instanceof WP_User ) {
        return false;
    }
    $userName = $currentUser->user_login;
    $userRoles = $currentUser->roles;
    $accessSettings = UserAccess::getInstance()->getAccessData( $userName, $userRoles );
    if ( empty( $accessSettings ) ) {
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        } else {
            return false;
        }
    }
    $accessSettingsPages = $accessSettings['pages'] ?? [];
    if ( !is_array( $accessSettingsPages ) || empty( $accessSettingsPages ) || is_wp_error( $accessSettingsPages ) ) {
        return false;
    }
    $accessFolders = $accessSettings['folders'] ?? [];
    if ( !is_array( $accessFolders ) || is_wp_error( $accessFolders ) || empty( $accessFolders ) ) {
        return false;
    }
    return [
        'pages'   => $accessSettingsPages,
        'folders' => $accessFolders,
    ];
}

function ccpidbHasUserAccessToFolder(  $folderKey  ) {
    $accessData = ccpidbGetCurrentUserAccess();
    if ( $accessData === true ) {
        return true;
    }
    if ( $accessData === false ) {
        return false;
    }
    $allowedFolders = $accessData['folders'] ?? [];
    if ( empty( $allowedFolders ) || !is_array( $allowedFolders ) ) {
        return false;
    }
    if ( Helpers::validateFileKey( $folderKey, $allowedFolders ) ) {
        return true;
    }
    return false;
}

function ccpidbHasUserAccessPage(  ... $pages  ) {
    $accessData = ccpidbGetCurrentUserAccess();
    if ( $accessData === true ) {
        return true;
    }
    if ( $accessData === false ) {
        return false;
    }
    $allowedPages = $accessData['pages'] ?? [];
    if ( empty( $allowedPages ) || !is_array( $allowedPages ) ) {
        return false;
    }
    if ( empty( $pages ) ) {
        return true;
    }
    if ( count( $pages ) === 1 && is_array( $pages[0] ) ) {
        $pages = $pages[0];
    }
    return empty( array_diff( $pages, $allowedPages ) );
}
