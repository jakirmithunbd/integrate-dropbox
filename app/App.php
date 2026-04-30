<?php

namespace CodeConfig\IDB\App;

use CodeConfig\IDB\App\API\Files as APIFiles;
use function CodeConfig\IDB\ccpidb_fs;
use CodeConfig\IDB\Models\BaseModel;
use CodeConfig\IDB\Models\Files as ModelFiles;
use CodeConfig\IDB\Utils\Helpers;
use CodeConfig\IDB\Utils\Singleton;
use function defined;
use function in_array;
use function is_array;
use function strlen;
use WP_Error;
defined( 'ABSPATH' ) or exit( 'Hey, what are you doing here? You silly human!' );
class App {
    use Singleton;
    private $accountId;

    private $model;

    public function __construct( $accountId ) {
        if ( empty( $accountId ) ) {
            $account = Accounts::getInstance()->getAccount();
            if ( !empty( $account ) ) {
                $accountId = $account->getId();
            }
        }
        $this->accountId = $accountId;
        $this->model = ModelFiles::getInstance();
    }

    public function getFile( $fileKey, $from = 'cache', $output = 'object' ) {
        $file = $this->model->getFile( $fileKey, $output );
        if ( empty( $file ) || is_wp_error( $file ) ) {
            return new WP_Error(404, __( 'File not found', 'integrate-dropbox' ));
        }
        if ( 'server' === $from && !empty( $file->getPath() ) && !empty( $file->getAccountId() ) ) {
            $file = ( new APIFiles($file->getAccountId()) )->getFile( $file->getPath() );
        }
        return $file;
    }

    public function getFilesByKeys( $fileKeys, $queryConfig = [] ) {
        if ( empty( $fileKeys ) ) {
            return new WP_Error(404, __( 'No file keys provided.', 'integrate-dropbox' ));
        }
        if ( !is_array( $fileKeys ) ) {
            return new WP_Error(400, __( 'File keys must be an array.', 'integrate-dropbox' ));
        }
        $queryConfig = wp_parse_args( $queryConfig, [
            'returnType'  => 'array',
            'recursive'   => true,
            'page'        => 1,
            'perPage'     => 25,
            'orderBy'     => 'createdAt',
            'order'       => 'DESC',
            'search'      => '',
            'searchScope' => 'folder',
            'from'        => 'cache',
            'types'       => [],
        ] );
        if ( isset( $fileKeys[0]['fileKey'] ) ) {
            $fileKeys = wp_list_pluck( $fileKeys, 'fileKey' );
        }
        $filesModel = ModelFiles::getInstance();
        if ( isset( $queryConfig['from'] ) && $queryConfig['from'] === 'server' ) {
            $filesData = $filesModel->getValuesByKeys(
                ['path', 'accountId'],
                $fileKeys,
                [
                    'extension' => 'folder',
                ],
                ['%s']
            );
            if ( is_wp_error( $filesData ) ) {
                return $filesData;
            }
            if ( !empty( $filesData ) ) {
                foreach ( $filesData as $fileData ) {
                    $path = $fileData->path;
                    $accountId = $fileData->accountId;
                    $this->accountId = $accountId;
                    $apiFiles = new APIFiles($this->accountId);
                    $files = $apiFiles->getFolder( $path );
                    if ( $queryConfig['recursive'] ) {
                        $folders = array_filter( $files, fn( $item ) => !empty( $item['isDir'] ) );
                        if ( !empty( $folders ) ) {
                            $this->getFilesByKeys( $folders, $queryConfig );
                        }
                    }
                }
            }
        }
        $filesData = $filesModel->getFilesByKeys( $fileKeys, $queryConfig );
        return $filesData;
    }

    public function getFolder( $folderKey, $config = [] ) {
        $defaultOptions = [
            'from'        => 'cache',
            'perPage'     => 25,
            'page'        => 1,
            'orderBy'     => 'updatedAt',
            'order'       => 'desc',
            'types'       => [],
            'search'      => '',
            'searchScope' => 'folder',
        ];
        $config = wp_parse_args( $config, $defaultOptions );
        $cacheFrom = $config['from'];
        $perPage = (int) $config['perPage'];
        unset($config['from']);
        if ( $cacheFrom === 'server' ) {
            $config['perPage'] = BaseModel::MAX_ITEMS_PER_PAGE;
        }
        $folder = $this->model->getFolder( $folderKey, $config );
        if ( is_wp_error( $folder ) ) {
            return new WP_Error(400, __( 'Error: ', 'integrate-dropbox' ) . $folder->get_error_message());
        }
        $files = $folder['files'] ?? [];
        $isNewFolder = false;
        if ( $cacheFrom === 'server' || empty( $files ) ) {
            $folderPathInfo = $this->resolveFolderPath( $folderKey, $files );
            if ( is_wp_error( $folderPathInfo ) ) {
                return $folderPathInfo;
            }
            [$path, $accountId] = $folderPathInfo;
            $this->accountId = $accountId;
            $apiFiles = new APIFiles($this->accountId);
            if ( !empty( $config['search'] ) && $config['searchScope'] === 'global' ) {
                $newFiles = $apiFiles->searchFile( $config['search'], $path );
            } else {
                $newFiles = $apiFiles->getFolder( $path, [
                    'fileKeys'  => wp_list_pluck( $files, 'fileKey' ),
                    'folderKey' => $folderKey,
                ] );
            }
            if ( is_wp_error( $newFiles ) ) {
                return $newFiles;
            }
            $isNewFolder = !empty( array_filter( $newFiles, fn( $item ) => $item['isDir'] ) );
            $config['perPage'] = $perPage;
            // Flush cache for the current folder
            wp_cache_flush_group( "ccpidb_files_" . md5( "{$path}_{$this->accountId}" ) );
            $folder = $this->model->getFolder( $folderKey, $config );
            if ( is_wp_error( $folder ) ) {
                return new WP_Error(400, __( 'Error: ', 'integrate-dropbox' ) . $folder->get_error_message());
            }
        }
        $folder['isNewFolder'] = $isNewFolder;
        return $folder;
    }

    private function resolveFolderPath( $folderKey, $folder ) {
        if ( empty( $folder ) ) {
            if ( $folderKey === '/' ) {
                $path = '/';
            } else {
                $file = $this->model->getFile( $folderKey );
                if ( empty( $file ) || is_wp_error( $file ) ) {
                    return new WP_Error(404, __( 'File not found', 'integrate-dropbox' ));
                }
                $path = $file->getPath();
            }
            $account = Accounts::getInstance()->getAccount();
            $accountId = $account->getId();
            return [$path, $accountId];
        }
        $firstChild = reset( $folder );
        return [$firstChild['parent'], $firstChild['accountId']];
    }

    public function renameFolder( $fileKey, $name ) {
        if ( empty( $fileKey ) || empty( $name ) ) {
            return new WP_Error('invalid_params', __( 'Invalid file key or name.', 'integrate-dropbox' ));
        }
        $file = $this->model->getFile( $fileKey );
        if ( !$file instanceof File ) {
            return new WP_Error('file_not_found', __( 'File not found.', 'integrate-dropbox' ));
        }
        $extension = $file->getExtension();
        if ( $extension && str_ends_with( $name, ".{$extension}" ) ) {
            $name = substr( $name, 0, -(strlen( $extension ) + 1) );
        }
        $finalName = ( $extension && $extension !== 'folder' ? "{$name}.{$extension}" : $name );
        $apiFiles = new APIFiles($file->getAccountId());
        $result = $apiFiles->renameFile( $finalName, $file->getPath() );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return $result->getData();
    }

    public function deleteFiles( $fileKeys ) {
        $paths = $this->model->getPathsByKeys( $fileKeys );
        if ( empty( $paths ) || is_wp_error( $paths ) ) {
            return new WP_Error(404, __( 'File not found', 'integrate-dropbox' ));
        }
        $accountId = $this->model->getAccountIdByFileKey( $fileKeys[0] );
        $apiFiles = new APIFiles($accountId);
        return $apiFiles->deleteFiles( $paths );
    }

    public function createFolder( $name, $fileKey ) {
        if ( $fileKey === '/' || empty( $fileKey ) ) {
            $path = '/';
            $account = Accounts::getInstance()->getAccount();
            $accountId = $account->getId();
            $this->accountId = $accountId;
        } else {
            $file = $this->model->getFile( $fileKey );
            if ( empty( $file ) || is_wp_error( $file ) ) {
                return new WP_Error(404, __( 'File not found', 'integrate-dropbox' ));
            }
            $this->accountId = $file->getAccountId();
            $path = $file->getPath();
        }
        $apiFiles = new APIFiles($this->accountId);
        if ( is_wp_error( $apiFiles ) ) {
            return $apiFiles;
        }
        return $apiFiles->createFolder( $name, $path );
    }

    public function getPreview( $fileKey ) {
        $shareLink = $this->getShareLink( $fileKey );
        if ( is_wp_error( $shareLink ) ) {
            return $shareLink;
        }
        return Helpers::shareLinkToPreviewLink( $shareLink );
    }

    public function downloadLink( $fileKey ) {
        $shareLink = $this->getShareLink( $fileKey );
        if ( is_wp_error( $shareLink ) ) {
            return $shareLink;
        }
        return Helpers::shareLinkToDownloadLink( $shareLink );
    }

    public function generateSharedLink( $fileKey, $options = [] ) {
        $file = $this->model->getFile( $fileKey );
        if ( empty( $file ) || is_wp_error( $file ) ) {
            return new WP_Error(404, __( 'File not found', 'integrate-dropbox' ));
        }
        $sharedKey = $this->model->getSharedKey( $fileKey, [
            'expireIn' => $options['expireIn'] ?? 3600,
            'password' => $options['password'] ?? null,
        ] );
        if ( empty( $sharedKey ) || is_wp_error( $sharedKey ) ) {
            return new WP_Error(401, __( 'Unable to generate shared link.', 'integrate-dropbox' ));
        }
        $link = ccpidbGetUrl(
            'share',
            $sharedKey,
            $file->getName(),
            null,
            $file->getExtension()
        );
        return $link;
    }

    public function updateSharedLink( $fileKey, $shareLinkId, $options = [] ) {
        $file = $this->model->getFile( $fileKey );
        if ( empty( $file ) || is_wp_error( $file ) ) {
            return new WP_Error('file_not_found', __( 'File not found', 'integrate-dropbox' ));
        }
        $updatedSharedData = $this->model->updateSharedKey( $fileKey, $shareLinkId, [
            'expireIn' => $options['expireIn'] ?? null,
            'password' => $options['password'] ?? null,
        ] );
        if ( empty( $updatedSharedData ) || is_wp_error( $updatedSharedData ) ) {
            return new WP_Error('shared_link_error', __( 'Unable to update shared link.', 'integrate-dropbox' ));
        }
        $link = ccpidbGetUrl(
            'share',
            $shareLinkId,
            $file->getName(),
            null,
            $file->getExtension()
        );
        return [
            'link'        => $link,
            'updatedData' => [
                $shareLinkId => $updatedSharedData[$shareLinkId],
            ] ?? [],
        ];
    }

    public function deleteSharedLink( $fileKey, $shareLinkId ) {
        $file = $this->model->getFile( $fileKey );
        if ( empty( $file ) || is_wp_error( $file ) ) {
            return new WP_Error('file_not_found', __( 'File not found', 'integrate-dropbox' ));
        }
        $result = $this->model->deleteSharedKey( $fileKey, $shareLinkId );
        if ( is_wp_error( $result ) ) {
            return new WP_Error('shared_link_deletion_error', __( 'Unable to delete shared link.', 'integrate-dropbox' ));
        }
        return true;
    }

    public function generateDownloadLink( $fileKey, $options = [] ) {
        $file = $this->model->getFile( $fileKey );
        if ( empty( $file ) || is_wp_error( $file ) ) {
            return new WP_Error(404, __( 'File not found', 'integrate-dropbox' ));
        }
        $downloadKey = $this->model->getDownloadKey( $fileKey, [
            'expireIn' => $options['expireIn'] ?? 3600,
            'password' => $options['password'] ?? null,
            'limit'    => $options['limit'] ?? 0,
        ] );
        if ( is_wp_error( $downloadKey ) ) {
            return $downloadKey;
        }
        if ( empty( $downloadKey ) ) {
            return new WP_Error(401, __( 'Unable to generate download link.', 'integrate-dropbox' ));
        }
        $link = ccpidbGetUrl(
            'download',
            $downloadKey,
            $file->getName(),
            null,
            $file->getExtension()
        );
        return $link;
    }

    public function updateDownloadLink( $fileKey, $downloadKeyId, $options = [] ) {
        $file = $this->model->getFile( $fileKey );
        if ( empty( $file ) || is_wp_error( $file ) ) {
            return new WP_Error('file_not_found', __( 'File not found', 'integrate-dropbox' ));
        }
        $updatedData = $this->model->updateDownloadKey( $fileKey, $downloadKeyId, [
            'expireIn' => $options['expireIn'] ?? null,
            'password' => $options['password'] ?? null,
            'limit'    => $options['limit'] ?? null,
        ] );
        if ( is_wp_error( $updatedData ) ) {
            return $updatedData;
        }
        if ( empty( $updatedData ) ) {
            return new WP_Error('download_link_error', __( 'Unable to update download link.', 'integrate-dropbox' ));
        }
        $link = ccpidbGetUrl(
            'download',
            $downloadKeyId,
            $file->getName(),
            null,
            $file->getExtension()
        );
        return [
            'link'        => $link,
            'updatedData' => [
                $downloadKeyId => $updatedData[$downloadKeyId] ?? [],
            ],
        ];
    }

    public function deleteDownloadLink( $fileKey, $downloadKeyId ) {
        $file = $this->model->getFile( $fileKey );
        if ( empty( $file ) || is_wp_error( $file ) ) {
            return new WP_Error('file_not_found', __( 'File not found', 'integrate-dropbox' ));
        }
        $result = $this->model->deleteDownloadKey( $fileKey, $downloadKeyId );
        if ( is_wp_error( $result ) ) {
            return new WP_Error('download_link_deletion_error', __( 'Unable to delete download link.', 'integrate-dropbox' ));
        }
        return true;
    }

    public function getShareLink( $fileKey ) {
        $file = $this->model->getFile( $fileKey );
        if ( empty( $file ) || is_wp_error( $file ) ) {
            return new WP_Error(404, __( 'File not found', 'integrate-dropbox' ));
        }
        if ( $file->isDir() ) {
            return new WP_Error(401, __( 'Generating shared links/download links for folders is a premium feature. Please upgrade to the premium version to access this feature.', 'integrate-dropbox' ));
        }
        if ( !empty( $file->getShareLink() ) && esc_url( $file->getShareLink() ) ) {
            return $file->getShareLink();
        }
        $path = $file->getPath();
        $apiFiles = new APIFiles($file->getAccountId());
        $link = $apiFiles->createSharedLink( $path );
        if ( is_wp_error( $link ) ) {
            return $link;
        }
        if ( !empty( $link ) ) {
            $this->model->updateFile(
                [
                    'fileKey' => $fileKey,
                ],
                [
                    'sharedLink' => $link,
                ],
                ['%s'],
                ['%s']
            );
        }
        return $link;
    }

    public function getEmbeddedLink( $fileKey ) {
        $file = $this->model->getFile( $fileKey );
        if ( empty( $file ) || is_wp_error( $file ) ) {
            return new WP_Error(404, __( 'File not found', 'integrate-dropbox' ));
        }
        if ( false !== $file->getAdditionalData( 'canPreviewByCloud' ) || in_array( $file->getExtension(), [
            'pdf',
            'jpg',
            'jpeg',
            'png',
            'gif'
        ] ) || in_array( $file->getExtension(), [
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
            'flac'
        ] ) ) {
            $previewLink = $this->getPreview( $fileKey );
            return $previewLink;
        }
        // Preview for Office files via Office Viewer (Except read-only)
        if ( in_array( $file->getExtension(), [
            'xls',
            'xlsx',
            'xlsm',
            'doc',
            'docx',
            'docm',
            'ppt',
            'pptx',
            'pptm',
            'pps',
            'ppsm',
            'ppsx'
        ] ) ) {
            $downloadLink = $this->downloadLink( $fileKey );
            return 'https://view.officeapps.live.com/op/embed.aspx?src=' . urlencode( $downloadLink );
        }
    }

    public function searchFiles( $query, $options = [] ) {
        $defaults = [
            'folderKey' => '',
            'from'      => 'cache',
            'scope'     => 'folder',
            'types'     => [],
        ];
        $options = wp_parse_args( $options, $defaults );
        $path = '';
        if ( !empty( $options['folderKey'] ) && $options['folderKey'] !== '/' ) {
            $file = $this->model->getFile( $options['folderKey'] );
            if ( !is_wp_error( $file ) ) {
                $path = $file->getPath();
                $this->accountId = $file->getAccountId();
            }
        }
        if ( empty( $path ) || $options['scope'] === 'global' ) {
            $path = '/';
        }
        if ( empty( $this->accountId ) ) {
            $account = Accounts::getInstance()->getAccount();
            $this->accountId = $account->getId();
        }
        if ( empty( $this->accountId ) ) {
            return new WP_Error(404, __( 'Account ID is required.', 'integrate-dropbox' ));
        }
        if ( $options['from'] === 'server' ) {
            $searchPath = ( $options['scope'] === 'global' ? '/' : $path );
            $apiFiles = new APIFiles($this->accountId);
            $results = $apiFiles->searchFile( $query, $searchPath );
            if ( is_wp_error( $results ) ) {
                return $results;
            }
        }
        $searchOptions = [
            'path'      => $path,
            'scope'     => $options['scope'],
            'types'     => $options['types'],
            'accountId' => $this->accountId,
            'perPage'   => $options['perPage'] ?? 20,
            'page'      => $options['page'] ?? 1,
            'orderBy'   => $options['orderBy'] ?? 'name',
            'order'     => $options['order'] ?? 'ASC',
        ];
        return $this->model->searchFiles( $query, $searchOptions );
    }

    public function uploadFile( $folderKey, $file ) {
        $folderPath = '/';
        $accountId = $this->accountId;
        if ( !empty( $folderKey ) && $folderKey !== '/' ) {
            $folder = $this->model->getFile( $folderKey );
            if ( empty( $folder ) || is_wp_error( $folder ) ) {
                return new WP_Error(404, __( 'Folder not found', 'integrate-dropbox' ));
            }
            $folderPath = $folder->getPath();
            $accountId = $folder->getAccountId();
        }
        if ( $folderKey === '/' ) {
            $account = Accounts::getInstance()->getAccount();
            if ( !$account instanceof \CodeConfig\IDB\App\Account ) {
                return new WP_Error(404, __( 'No account found for uploading to root directory.', 'integrate-dropbox' ));
            }
            $accountId = $account->getId();
            $isTeamEnabled = $account->isTeam();
            if ( $isTeamEnabled ) {
                return new WP_Error(400, __( 'Uploading files to root folder is not allowed for team accounts.', 'integrate-dropbox' ));
            }
            $this->accountId = $accountId;
        }
        $apiFiles = new APIFiles($accountId);
        if ( empty( $file['name'] ) || strpos( $file['name'], '.' ) === false ) {
            return new WP_Error(400, __( 'Invalid file data', 'integrate-dropbox' ));
        }
        $path = ( $folderPath === '/' ? '/' . $file['name'] : $folderPath . '/' . $file['name'] );
        $file = $apiFiles->directUploadFile( $file['tmp_name'], $path );
        if ( is_wp_error( $file ) ) {
            return $file;
        }
        return $file;
    }

    public function uploadFileChunkStart( $folderKey, $dataChunk ) {
        if ( empty( $dataChunk ) || !is_string( $dataChunk ) ) {
            return new WP_Error(400, __( 'Invalid parameters', 'integrate-dropbox' ));
        }
        $accountId = $this->accountId;
        if ( !empty( $folderKey ) && $folderKey !== '/' ) {
            $folder = $this->model->getFile( $folderKey );
            if ( empty( $folder ) || is_wp_error( $folder ) ) {
                return new WP_Error(404, __( 'Folder not found', 'integrate-dropbox' ));
            }
            $accountId = $folder->getAccountId();
        }
        $apiFiles = new APIFiles($accountId);
        return $apiFiles->uploadSessionStart( $dataChunk );
    }

    public function uploadFileChunkAppend(
        $folderKey,
        $sessionId,
        $offset,
        $dataChunk
    ) {
        if ( empty( $dataChunk ) || !is_string( $dataChunk ) || empty( $sessionId ) || !is_int( $offset ) ) {
            return new WP_Error(400, __( 'Invalid parameters', 'integrate-dropbox' ));
        }
        $accountId = $this->accountId;
        if ( !empty( $folderKey ) && $folderKey !== '/' ) {
            $folder = $this->model->getFile( $folderKey );
            if ( empty( $folder ) || is_wp_error( $folder ) ) {
                return new WP_Error(404, __( 'Folder not found', 'integrate-dropbox' ));
            }
            $accountId = $folder->getAccountId();
        }
        $apiFiles = new APIFiles($accountId);
        return $apiFiles->appendUploadSession( $dataChunk, $sessionId, $offset );
    }

    public function finishUploadSession(
        $folderKey,
        $sessionId,
        $offset,
        $dataChunk,
        $name,
        $params = []
    ) {
        if ( empty( $dataChunk ) || !is_string( $dataChunk ) || empty( $sessionId ) || !is_int( $offset ) ) {
            return new WP_Error(400, __( 'Invalid parameters', 'integrate-dropbox' ));
        }
        $accountId = $this->accountId;
        $folderPath = '/';
        if ( !empty( $folderKey ) && $folderKey !== '/' ) {
            $folder = $this->model->getFile( $folderKey );
            if ( empty( $folder ) || is_wp_error( $folder ) ) {
                return new WP_Error(404, __( 'Folder not found', 'integrate-dropbox' ));
            }
            $folderPath = $folder->getPath();
            $accountId = $folder->getAccountId();
        }
        $path = ( $folderPath === '/' ? "/{$name}" : "{$folderPath}/{$name}" );
        $apiFiles = new APIFiles($accountId);
        return $apiFiles->finishUploadSession(
            $dataChunk,
            $sessionId,
            $offset,
            $path,
            $params
        );
    }

    public function getUploadedFile( $token ) {
        if ( empty( $token ) ) {
            return new WP_Error(400, __( 'Invalid parameters', 'integrate-dropbox' ));
        }
        $path = Helpers::decode( $token );
        $apiFiles = new APIFiles($this->accountId);
        $file = $apiFiles->getFile( $path );
        return $file->getData();
    }

    public function moveFiles( $fileKeys, $destination ) {
        $from_path = $this->model->getPathsByKeys( $fileKeys );
        $to_path = $this->model->getPathsByKeys( $destination );
        if ( empty( $from_path ) || is_wp_error( $to_path ) ) {
            return new WP_Error(404, __( 'Files not found', 'integrate-dropbox' ));
        }
        $accountId = $this->model->getAccountIdByFileKey( $fileKeys[0] );
        $apiFiles = new APIFiles($accountId);
        return $apiFiles->moveFiles( $from_path, $to_path );
    }

    public function copyFiles( $fileKeys, $destination ) {
        $from_path = $this->model->getPathsByKeys( $fileKeys );
        $to_path = $this->model->getPathsByKeys( $destination );
        if ( empty( $from_path ) || is_wp_error( $to_path ) ) {
            return new WP_Error(404, __( 'Files not found', 'integrate-dropbox' ));
        }
        $accountId = $this->model->getAccountIdByFileKey( $fileKeys[0] );
        $apiFiles = new APIFiles($accountId);
        return $apiFiles->copyFiles( $from_path, $to_path );
    }

    public function getFolderTree( $folderKey, $shortcodeId = null ) {
        $folderKeys = [$folderKey];
        if ( !empty( $shortcodeId ) ) {
            $folders = Helpers::validateShortcodeKey( $shortcodeId, $folderKey );
            if ( empty( $folderKeys ) || is_wp_error( $folderKeys ) ) {
                return new WP_Error(403, 'You do not have permission to access this resource.', [
                    'status' => 403,
                ]);
            }
            $folderKeys = (array) $folders;
        } else {
            $userAccess = ccpidbGetCurrentUserAccess();
            if ( empty( $userAccess ) ) {
                return new WP_Error(403, 'You do not have permission to access this resource.', [
                    'status' => 403,
                ]);
            }
            if ( !empty( $userAccess['folders'] ) ) {
                $folders = $userAccess['folders'];
                if ( '/' === $folderKey ) {
                    $folderKeys = $folders;
                } elseif ( Helpers::validateFileKey( $folderKey, $folders ) === false ) {
                    return new WP_Error(403, 'You do not have permission to access this resource.', [
                        'status' => 403,
                    ]);
                }
            }
        }
        $paths = ['/'];
        if ( !in_array( '/', $folderKeys, true ) ) {
            $paths = $this->model->getPathsByKeys( $folderKeys );
        }
        $accountId = $this->accountId;
        $folders = $this->model->getFolderTree( $accountId, ...$paths );
        if ( is_wp_error( $folders ) ) {
            return $folders;
        }
        return $folders;
    }

    private function separateFilesAndFolders( array $files ) : array {
        $result = [
            'files'   => [],
            'folders' => [],
        ];
        foreach ( $files as $file ) {
            if ( !empty( $file['isDir'] ) ) {
                $result['folders'][] = $file;
            } else {
                $result['files'][] = $file;
            }
        }
        return $result;
    }

    public function getThumbnailData( $fileKey, $size = 'md', $format = 'webp' ) {
        $size = ccpidbGetThumbnailSize( $size );
        $file = $this->model->getFile( $fileKey );
        if ( empty( $file ) || is_wp_error( $file ) ) {
            return new WP_Error(404, __( 'File not found', 'integrate-dropbox' ));
        }
        $apiFiles = new APIFiles($file->getAccountId());
        $thumbnailData = $apiFiles->getThumbnail( $file->getPath(), $size, $format );
        if ( is_wp_error( $thumbnailData ) ) {
            return $thumbnailData;
        }
        if ( empty( $file->getThumbnailRatio() ) ) {
            $mediaInfo = getimagesizefromstring( base64_decode( $thumbnailData ) );
            if ( $mediaInfo && isset( $mediaInfo['0'], $mediaInfo['1'] ) ) {
                $width = $mediaInfo[0];
                $height = $mediaInfo[1];
                $aspectRation = Helpers::getAspectRatio( $width, $height );
                ModelFiles::getInstance()->updateFile(
                    [
                        'fileKey' => $file->getFileKey(),
                    ],
                    [
                        'thumbnailRatio' => $aspectRation,
                    ],
                    ['%s'],
                    ['%s']
                );
            }
        }
        return [
            'fileContents' => $thumbnailData,
            'file'         => $file,
        ];
    }

}
