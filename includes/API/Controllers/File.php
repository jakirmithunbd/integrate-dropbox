<?php

namespace CodeConfig\IDB\API\Controllers;

use CodeConfig\IDB\API\BaseController;
use CodeConfig\IDB\App\Accounts;
use CodeConfig\IDB\App\App;
use CodeConfig\IDB\App\File as AppFile;
use CodeConfig\IDB\Content;
use CodeConfig\IDB\Integrations\MediaLibrary__premium_only\Importer;
use CodeConfig\IDB\Models\Attachment;
use CodeConfig\IDB\Models\Files as ModelFiles;
use CodeConfig\IDB\Models\Shortcode;
use CodeConfig\IDB\Shortcode\Notifications;
use CodeConfig\IDB\Utils\Helpers;

use function strlen;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined('ABSPATH') || exit('No direct script access allowed');

class File extends BaseController
{
    public function __construct()
    {
        parent::__construct('integrate-dropbox/v1', 'file');
    }

    public function register_routes(): void
    {

        register_rest_route($this->namespace, "{$this->rest_base}/rename", [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'rename'],
                'permission_callback' => [$this, 'managePermission'],
            ]
        ]);

        register_rest_route($this->namespace, "{$this->rest_base}/open-in-dropbox/(?P<fileKey>[^/]+)", [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'openInDropbox'],
                'permission_callback' => [$this, 'managePermission'],
            ]
        ]);

        register_rest_route($this->namespace, "{$this->rest_base}/share/(?P<fileKey>[^/]+)", [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'shareLink'],
                'permission_callback' => [$this, 'managePermission'],
                'args'                => [
                    'expiry'   => [
                        'required' => false,
                        'type'     => 'integer',
                        'default'  => 3600,
                    ],
                    'password' => [
                        'required' => false,
                        'type'     => 'string',
                        'default'  => null,
                    ],
                    'shortcodeId' => [
                        'required' => false,
                        'type'     => 'string',
                        'default'  => null,
                    ],
                ],
            ]
        ]);

        register_rest_route($this->namespace, "{$this->rest_base}/download/(?P<fileKey>[^/]+)", [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'downloadLink'],
                'permission_callback' => [$this, 'managePermission'],
                'args'                => [
                    'expiry'   => [
                        'required' => false,
                        'type'     => 'integer',
                        'default'  => 3600,
                    ],
                    'limit' => [
                        'required' => false,
                        'type'     => 'integer',
                        'default'  => null,
                    ],
                    'password' => [
                        'required' => false,
                        'type'     => 'string',
                        'default'  => null,
                    ],
                    'shortcodeId' => [
                        'required' => false,
                        'type'     => 'string',
                        'default'  => null,
                    ],
                ],
            ]
        ]);

        register_rest_route($this->namespace, "{$this->rest_base}/search", [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'search'],
                'permission_callback' => [$this, 'manageFilePermission'],
            ]
        ]);

        register_rest_route($this->namespace, "{$this->rest_base}/copy", [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'copy'],
                'permission_callback' => [$this, 'managePermission'],
            ]
        ]);

        register_rest_route($this->namespace, "{$this->rest_base}/move", [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'move'],
                'permission_callback' => [$this, 'managePermission'],
            ]
        ]);

        register_rest_route($this->namespace, "{$this->rest_base}/upload", [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'upload'],
                'permission_callback' => [$this, 'managePermission'],
                'args'                => [
                    'name' => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                    'chunk' => [
                        'type'     => 'integer',
                        'required' => false,
                        'default'  => 0,
                    ],
                    'chunks' => [
                        'type'     => 'integer',
                        'required' => false,
                        'default'  => 1,
                    ],
                ]
            ]
        ]);

        register_rest_route($this->namespace, "{$this->rest_base}/by-keys", [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'getFiles'],
                'permission_callback' => [$this, 'managePermission'],
            ]
        ]);

        register_rest_route($this->namespace, "{$this->rest_base}/(?P<fileKey>[^/]+)", [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get'],
                'permission_callback' => [$this, 'managePermission'],
            ]
        ]);

        register_rest_route($this->namespace, "{$this->rest_base}/", [
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'delete'],
                'permission_callback' => [$this, 'managePermission'],
            ]
        ]);

        register_rest_route($this->namespace, "{$this->rest_base}/(?P<action>thumbnail|attachment|preview|share|download)(?:-(?P<variant>\d+))?/(?P<fileKey>[^/]+)/(?P<name>[^/]+)", [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'fileAction'],
                'permission_callback' => '__return_true',
            ]
        ]);
    }

    public function fileAction(WP_REST_Request $request): void
    {
        $allowedActions = [
            'thumbnail'  => true,
            'attachment' => true,
            'preview'    => true,
            'share'      => true,
            'download'   => true,
        ];

        $action        = sanitize_key($request->get_param('action'));
        $fileKey       = sanitize_text_field($request->get_param('fileKey'));
        $nameExt       = sanitize_text_field(urldecode($request->get_param('name')));
        $variant       = absint($request->get_param('variant')) ?: null;

        if (empty($allowedActions[ $action ])) {
            $ext = pathinfo($nameExt, PATHINFO_EXTENSION);
            wp_safe_redirect(Helpers::defaultIcon($ext, '256x256'));
            exit;
        }

        $pathInfo = pathinfo($nameExt);
        $name     = $pathInfo['filename']  ?? '';
        $ext      = $pathInfo['extension'] ?? '';

        $content = Content::getInstance();

        if (is_callable([ $content, $action ])) {
            $content->{$action}($fileKey, $name, $ext, $variant);
        }

        exit;
    }

    public function upload(WP_REST_Request $request)
    {
        try {
            $folderKey   = $request->get_header('X-Upload-FolderKey') ?: '/';
            $shortcodeId = $request->get_header('X-Upload-shortcodeId');
            $shortcode   = null;

            $chunk      = (int) ($request->get_param('chunk') ?? 0);
            $chunks     = (int) ($request->get_param('chunks') ?? 1);
            $name       = sanitize_file_name($request->get_param('name') ?? '');
            $offset     = (int) ($request->get_header('X-Upload-Offset') ?? 0);
            $sessionId  = sanitize_text_field($request->get_header('X-Upload-Session-Id') ?? '');
            $postId     = $request->get_header('X-Upload-postId')     ?? 0;
            $queueIndex = $request->get_header('X-Upload-queueIndex') ?? 0;

            if (empty($name)) {
                return $this->errorResponse('Missing file name', self::HTTP_BAD_REQUEST);
            }

            /*
            * Root folder rules (your existing logic preserved)
            */
            if ($folderKey === '/') {
                $account = Accounts::getInstance()->getAccount();
                if (!$account instanceof \CodeConfig\IDB\App\Account) {
                    return $this->errorResponse('No account found for uploading to root directory.', self::HTTP_FORBIDDEN);
                }

                if ($account->isTeam() && !$shortcodeId) {
                    return $this->errorResponse('Uploading files to the root directory is not allowed for team accounts.', self::HTTP_FORBIDDEN);
                }
            }

            if ($shortcodeId && $chunk + 1 === $chunks) {
                $shortcode = Shortcode::getInstance()->getShortcode($shortcodeId);

                if (is_wp_error($shortcode)) {
                    return $this->errorResponse($shortcode->get_error_message(), 500);
                }

                if ($folderKey == '/') {
                    $files = $shortcode['data']['source']['fileKeys'] ?? [];
                    if (empty($files)) {
                        return $this->errorResponse('No files found in the shortcode for root upload.', 400);
                    }

                    if ($shortcode['type'] === 'file-browser') {
                        if ($account->isTeam()) {
                            return $this->errorResponse('Uploading files to the root directory is not allowed for team accounts.', self::HTTP_FORBIDDEN);
                        }

                        if ($shortcode['data']['advanced']['fileBrowser']['headerOptions']['root_upload'] ?? false) {
                            $folderKey = '/';
                        } else {
                            return $this->errorResponse('Root upload is not allowed for this shortcode.', self::HTTP_FORBIDDEN);
                        }
                    }

                    if ($shortcode['type'] === 'file-uploader') {
                        $folderKey = $files[0]['fileKey'];
                    }
                }

                $template = $shortcode['data']['advanced']['fileUploader']['renameFile'] ?? '';
                if ($template) {
                    $name = $this->generateFileNameFromTemplate($template, [
                        'name'       => $name,
                        'postId'     => $postId,
                        'queueIndex' => $queueIndex,
                    ]);

                    $name = "#$shortcodeId - Module Upload/$name";
                }
            }

            global $wp_filesystem;
            if (empty($wp_filesystem)) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                WP_Filesystem();
            }

            // For php://input streams, file_get_contents is the standard approach
            // Add phpcs:ignore to suppress the warning for stream wrappers
            $data = file_get_contents('php://input'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

            if ($data === false || $data === '') {
                return $this->errorResponse('Empty upload chunk', self::HTTP_BAD_REQUEST);
            }

            $app = App::getInstance();
            /*
            * FIRST CHUNK → upload_session/start
            */
            if ($chunk === 0) {
                $result = $app->uploadFileChunkStart($folderKey, $data);
                if (is_wp_error($result)) {
                    return $this->errorResponse($result->get_error_message(), self::HTTP_INTERNAL_SERVER_ERROR);
                }

                return $this->successResponse([
                    'sessionId'  => $result,
                    'offset'     => strlen($data),
                ], 'Upload session started');
            }

            if (empty($sessionId) || empty($offset)) {
                return $this->errorResponse('Missing upload session ID or offset for chunked upload', self::HTTP_BAD_REQUEST);
            }

            /*
             * MIDDLE CHUNKS → upload_session/append_v2
             */
            if ($chunk + 1 < $chunks) {
                $app->uploadFileChunkAppend(
                    $folderKey,
                    $sessionId,
                    $offset,
                    $data
                );

                return $this->successResponse([
                    'offset'    => $offset + strlen($data),
                    'sessionId' => $sessionId,
                ], 'Chunk uploaded');
            }

            if ($chunk + 1 !== $chunks) {
                return $this->errorResponse('Invalid chunk index', self::HTTP_BAD_REQUEST);
            }

            if (empty($name)) {
                return $this->errorResponse('Missing file name for finishing upload', self::HTTP_BAD_REQUEST);
            }

            /*
             * LAST CHUNK → upload_session/finish
             */
            $uploaded = $app->finishUploadSession(
                $folderKey,
                $sessionId,
                $offset,
                $data,
                $name
            );

            if (is_wp_error($uploaded)) {
                return $this->errorResponse($uploaded->get_error_message(), self::HTTP_INTERNAL_SERVER_ERROR);
            }

            Notifications::notify(
                Notifications::UPLOAD,
                $shortcodeId,
                $uploaded['fileKey'],
            );

            if (!empty($shortcode) && !is_wp_error($uploaded) && $folderKey === '/') {
                $isRootUpload = $shortcode['data']['advanced']['fileBrowser']['headerOptions']['root_upload'] ?? false;

                $fileKey = $uploaded['fileKey'] ?? '';

                if ($shortcode['type'] === 'file-browser' && $isRootUpload && $fileKey) {
                    $result = Shortcode::getInstance()->insertFile($shortcodeId, $fileKey);
                }
            }

            return $this->successResponse($uploaded, 'File uploaded successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'Failed to upload file');
        }
    }

    public function move(WP_REST_Request $request)
    {
        try {
            $fileKeys    = $request->get_param('fileKeys');
            $destination = $request->get_param('destination');
            $shortcodeId = $request->get_param('shortcodeId');

            if (empty($fileKeys) || empty($destination)) {
                return $this->errorResponse('File keys and destination are required', self::HTTP_BAD_REQUEST);
            }

            $files         = App::getInstance()->moveFiles($fileKeys, $destination);

            if (is_wp_error($files)) {
                return $this->errorResponse($files->get_error_message(), self::HTTP_INTERNAL_SERVER_ERROR);
            }

            Notifications::notify(
                Notifications::MOVE,
                $shortcodeId,
                $fileKeys,
            );

            return $this->successResponse($files, 'Files moved successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'Failed to move files');
        }
    }

    public function copy(WP_REST_Request $request)
    {
        try {
            $fileKeys     = $request->get_param('fileKeys');
            $destination  = $request->get_param('destination');
            $shortcodeId  = $request->get_param('shortcodeId');

            if (empty($fileKeys) || empty($destination)) {
                return $this->errorResponse('File keys and destination are required', self::HTTP_BAD_REQUEST);
            }

            $files = App::getInstance()->copyFiles($fileKeys, $destination);

            if (is_wp_error($files)) {
                return $this->errorResponse($files->get_error_message(), self::HTTP_INTERNAL_SERVER_ERROR);
            }

            Notifications::notify(
                Notifications::COPY,
                $shortcodeId,
                $fileKeys,
            );

            return $this->successResponse($files, 'Files copied successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'Failed to copy files');
        }
    }

    public function get(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $fileKey = $request->get_param('fileKey');
            $from    = $request->get_param('from') ?? 'cache';

            if (empty($fileKey)) {
                return $this->errorResponse('File key is required', self::HTTP_BAD_REQUEST);
            }

            $file = App::getInstance()->getFile($fileKey, $from);

            if ($file instanceof AppFile) {
                return $this->successResponse($file->getData(), 'File retrieved successfully');
            }

            return $this->errorResponse('File not found', self::HTTP_NOT_FOUND);
        } catch (\Exception $e) {
            return $this->handleException($e, 'Failed to retrieve file');
        }
    }

    public function delete(WP_REST_Request $request)
    {
        try {
            $fileKeys              = $request->get_param('fileKeys');
            $shortcodeId           = $request->get_param('shortcodeId');
            $isMigrateAttachment   = $request->get_param('isMigrateAttachment') ?? false;

            if (empty($fileKeys)) {
                return $this->errorResponse('File key is required', self::HTTP_BAD_REQUEST);
            }

            if ($isMigrateAttachment) {
                Importer::importFileToMediaLibrary($fileKeys, true);
            } else {
                foreach ($fileKeys as $fileKey) {
                    if ($attachmentId = Attachment::exists($fileKey)) {
                        wp_delete_attachment($attachmentId, true);
                    }
                }
            }

            $file = App::getInstance()->deleteFiles($fileKeys);

            if (is_wp_error($file)) {
                return $this->errorResponse($file->get_error_message(), self::HTTP_INTERNAL_SERVER_ERROR);
            }

            Notifications::notify(
                Notifications::DELETE,
                $shortcodeId,
                $fileKeys,
            );

            return $this->successResponse($file, 'File deleted successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'Failed to delete file');
        }
    }

    public function rename(WP_REST_Request $request)
    {
        try {
            $fileKey     = $request->get_param('fileKey');
            $name        = $request->get_param('name');
            $shortcodeId = $request->get_param('shortcodeId');

            if (empty($fileKey) || empty($name)) {
                return $this->errorResponse('File key and new name are required', self::HTTP_BAD_REQUEST);
            }

            $folder = App::getInstance()->renameFolder($fileKey, $name);

            if (is_wp_error($folder)) {
                return $this->errorResponse($folder->get_error_message(), self::HTTP_INTERNAL_SERVER_ERROR);
            }

            Notifications::notify(
                Notifications::RENAME,
                $shortcodeId,
                $fileKey,
            );

            return $this->successResponse($folder, 'Folder renamed successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'Failed to rename folder');
        }
    }

    public function openInDropbox(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $fileKey = $request->get_param('fileKey');

            if (empty($fileKey)) {
                return $this->errorResponse('File key is required', self::HTTP_BAD_REQUEST);
            }

            $shareLink = App::getInstance()->getShareLink($fileKey);

            if (is_wp_error($shareLink)) {
                return $this->errorResponse($shareLink->get_error_message(), self::HTTP_INTERNAL_SERVER_ERROR);
            }

            return $this->successResponse($shareLink, 'Preview retrieved successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'Failed to retrieve preview');
        }
    }

    public function shareLink(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $fileKey     = $request->get_param('fileKey');
            $expireIn    = $request->get_param('expireIn');
            $password    = $request->get_param('password');
            $shortcodeId = $request->get_param('shortcodeId');

            if (empty($fileKey)) {
                return $this->errorResponse('File key is required', self::HTTP_BAD_REQUEST);
            }

            $shareLink = App::getInstance()->generateSharedLink($fileKey, [
                'expireIn'   => $expireIn,
                'password'   => $password,
            ]);

            if (is_wp_error($shareLink)) {
                return $this->errorResponse($shareLink->get_error_message(), self::HTTP_INTERNAL_SERVER_ERROR);
            }

            Notifications::notify(
                Notifications::CREATE_SHARE_LINK,
                $shortcodeId,
                $fileKey,
            );

            return $this->successResponse($shareLink, 'Share link retrieved successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'Failed to retrieve share link');
        }
    }

    public function downloadLink(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $fileKey     = $request->get_param('fileKey');
            $expireIn    = $request->get_param('expireIn');
            $limit       = $request->get_param('limit');
            $password    = $request->get_param('password');
            $shortcodeId = $request->get_param('shortcodeId');

            if (empty($fileKey)) {
                return $this->errorResponse('File key is required', self::HTTP_BAD_REQUEST);
            }

            $shareLink = App::getInstance()->generateDownloadLink($fileKey, [
                'expireIn'   => $expireIn,
                'password'   => $password,
                'limit'      => $limit,
            ]);

            if (is_wp_error($shareLink)) {
                return $this->errorResponse($shareLink->get_error_message(), self::HTTP_INTERNAL_SERVER_ERROR);
            }

            Notifications::notify(
                Notifications::DOWNLOAD,
                $shortcodeId,
                $fileKey,
            );

            return $this->successResponse($shareLink, 'Download link retrieved successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'Failed to retrieve download link');
        }
    }

    public function search(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $query     = $request->get_param('query');
            $folderKey = $request->get_param('folderKey') ?? "/";
            $from      = $request->get_param('from')      ?? 'cache';
            $scope     = $request->get_param('scope')     ?? 'folder';
            $types     = $request->get_param('types')     ?? '';
            $perPage   = $request->get_param('perPage')   ?? 20;
            $page      = $request->get_param('page')      ?? 1;

            if (empty($query)) {
                return $this->errorResponse('Search query is required', self::HTTP_BAD_REQUEST);
            }

            $types = explode(',', $types);

            $results = App::getInstance()->searchFiles($query, [
                'folderKey' => $folderKey,
                'from'      => $from,
                'scope'     => $scope,
                'types'     => $types,
                'perPage'   => $perPage,
                'page'      => $page,
            ]);

            if (is_wp_error($results)) {
                return $this->errorResponse($results->get_error_message(), self::HTTP_INTERNAL_SERVER_ERROR);
            }

            return $this->successResponse($results, 'Search results retrieved successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'Failed to perform search');
        }
    }

    public function getFiles(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $fileKeys = $request->get_param('fileKeys');
            $fileKeys = explode(',', $fileKeys);

            if (empty($fileKeys)) {
                return $this->errorResponse('File keys are required', self::HTTP_BAD_REQUEST);
            }

            $files = ModelFiles::getInstance()->getFilesByKeys($fileKeys);

            if (is_wp_error($files)) {
                return $this->errorResponse('Files not found', self::HTTP_INTERNAL_SERVER_ERROR);
            }

            return $this->successResponse($files, 'Files retrieved successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'Failed to retrieve files');
        }
    }

    private function generateFileNameFromTemplate(string $template, array $file): string
    {
        $fileInfo       = explode('.', $file['name']);
        $extension      = array_pop($fileInfo);
        $baseName       = implode('.', $fileInfo);
        $currentDate    = gmdate('Y-m-d');
        $currentTime    = gmdate('H-i-s');
        $uniqueId       = uniqid();
        $queueIndex     = $file['queueIndex'] ?? '0';
        $postId         = $file['postId']     ?? '0';
        $postTitle      = get_the_title($postId);
        $postTitle      = (!empty($postTitle)) ? sanitize_title($postTitle) : "post-$postId";


        $newName     = str_replace(
            ['{file_name}', '{file_extension}', '{current_date}', '{current_time}', '{unique_id}', '{queue_index}', '{post_id}', '{post_title}'],
            [$baseName, $extension, $currentDate, $currentTime, $uniqueId, $queueIndex, $postId, $postTitle],
            $template
        );

        return "$newName.$extension";
    }
}
