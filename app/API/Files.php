<?php

namespace CodeConfig\IDB\App\API;

use CodeConfig\IDB\App\Accounts;
use CodeConfig\IDB\App\API;
use CodeConfig\IDB\App\File;
use CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException;
use CodeConfig\IDB\Dropbox\Models\FileLinkMetadata;
use CodeConfig\IDB\Dropbox\Models\FolderLinkMetadata;
use CodeConfig\IDB\Dropbox\Models\SharedLinkSettings;
use CodeConfig\IDB\Models\Files as ModelsFiles;
use CodeConfig\IDB\Utils\Helpers;

use function count;

use Exception;
use WP_Error;

defined('ABSPATH') || exit;

class Files extends API
{
    public const DIRECT_UPLOAD_MAX_FILE_SIZE = 10 * 1024 * 1024; // 290 MB

    public function __construct($accountId)
    {
        if (empty($accountId)) {
            $account   = Accounts::getInstance()->getAccount();
            if (empty($account)) {
                throw new Exception(esc_html__('No account found.', 'integrate-dropbox'));
            }

            $accountId = $account->getId();
        }

        parent::__construct($accountId);
    }

    /**
     * Gets the metadata of a file.
     *
     * @param string $path The path of the file. If it contains a '/', it will be cleaned.
     * @param array $params Additional parameters to pass to the Dropbox client. Defaults to ['include_media_info' => true].
     *
     * @return File|\WP_Error The metadata of the file, or false on failure.
     */
    public function getFile($path, $params = ['include_media_info' => true])
    {
        if (false !== strpos($path, '/')) {
            $path = ccpidbCleanFolderPath($path);
        }

        if ($path === '/' || $path === '') {
            return new \WP_Error('invalid_path', 'The root path is not a valid file path.');
        }

        try {
            $apiFile = $this->dropbox()->getMetadata($path, $params);

            return new File($apiFile->getFileData(['accountId' => $this->accountId, 'update' => true]));
        } catch (\Throwable $th) {
            return new \WP_Error('get_file_error', $th->getMessage());
        }
    }

    public function getFolder($path, $params = [], $output = 'array', $registerMediaInfoJob = true)
    {
        if (false !== strpos($path, '/')) {
            $path = ccpidbCleanFolderPath($path);
        }

        if ('/' === $path || empty($path)) {
            $path = '';
        }

        $defaults = [
            'recursive'                           => false,
            'include_media_info'                  => true,
            'include_deleted'                     => false,
            "include_has_explicit_shared_members" => false,
            "include_mounted_folders"             => true,
            "include_non_downloadable_files"      => true,
        ];

        $params   = wp_parse_args($params, $defaults);

        $apiFiles = [];

        try {
            $folderContents = $this->dropbox()->listFolder($path, [
                'recursive'                           => $params['recursive'],
                'include_media_info'                  => $params['include_media_info'],
                'include_deleted'                     => $params['include_deleted'],
                'include_has_explicit_shared_members' => $params['include_has_explicit_shared_members'],
                'include_mounted_folders'             => $params['include_mounted_folders'],
                'include_non_downloadable_files'      => $params['include_non_downloadable_files'],
            ]);

            $apiFiles = $folderContents->getItems()->toArray();

            while ($folderContents->hasMoreItems()) {
                $cursor               = $folderContents->getCursor();
                $folderContents       = $this->dropbox()->listFolderContinue($cursor);
                $apiFiles             = array_merge($apiFiles, $folderContents->getItems()->toArray());
            }

            $files = array_map(fn ($apiFile) => $output === 'object' ? new File($apiFile->getFileData(['accountId' => $this->accountId, 'update' => true])) : (new File($apiFile->getFileData(['accountId' => $this->accountId, 'update' => true])))->getData(), $apiFiles);

            $newFileKeys = [];

            if ($output === 'array') {
                $newFileKeys = wp_list_pluck($files, 'fileKey');
            } else {
                foreach ($files as $file) {
                    $newFileKeys[] = $file->getFileKey();
                }
            }

            $fileKeys = $params['fileKeys'] ?? [];

            if ($fileKeys) {
                $diffFileKeys = array_diff($fileKeys, $newFileKeys);

                if (!empty($diffFileKeys)) {
                    ModelsFiles::getInstance()->deleteFiles($diffFileKeys);
                }
            }

            if ($path !== '/' && $path !== '') {
                ModelsFiles::getInstance()->updateFile(
                    ['path' => $path, 'accountId' => $this->accountId],
                    ['size' => count($files)],
                    '%d',
                    ['%s', '%s']
                );
            }

            $newFetchingFileKeys = array_diff($newFileKeys, $fileKeys);

            if (empty($newFetchingFileKeys)) {
                return $files;
            }
            $excludeFolderKeys = [];

            if (!$registerMediaInfoJob) {
                foreach ($files as $file) {
                    $isDir = $output === 'array' ? (bool) ($file['isDir'] ?? false) : $file->isDir();
                    if ($isDir) {
                        $fileKey             = $output === 'array' ? $file['fileKey'] : $file->getFileKey();
                        $excludeFolderKeys[] = $fileKey;
                    }
                }
            }

            $newFetchingFileKeys = array_diff($newFetchingFileKeys, $excludeFolderKeys);

            if (!empty($newFetchingFileKeys)) {
                Helpers::registerScheduledMediaInfoGeneration($newFetchingFileKeys);
            }

            return $files;

        } catch (Exception $th) {
            return new WP_Error(401, $th->getMessage());
        }
    }

    public function createFolder(string $folderName, string $parentFolderPath, bool $autoRename = true)
    {
        $folderPath = ccpidbCleanFolderPath("$parentFolderPath/$folderName");

        try {
            $apiFile   = $this->dropbox()->createFolder($folderPath, $autoRename);
            $newFile   = new File($apiFile->getFileData(['accountId' => $this->accountId, 'update' => true]));
        } catch (DropboxClientException $ex) {
            if (false !== strpos($ex->getErrorSummary(), 'path/conflict/folder/')) {
                return self::getFile($folderPath);
            }

            return new WP_Error(401, esc_html__('Failed to create folder: ', 'integrate-dropbox') . $ex->getMessage());
        }

        return $newFile;
    }

    public function deleteFiles(array $filePaths)
    {
        if (empty($filePaths)) {
            return new WP_Error(401, __('No file paths provided for deletion.', 'integrate-dropbox'));
        }

        $filteredPaths = array_filter(array_map(fn ($p) => ['path' => trim($p)], $filePaths), fn ($item) => !empty($item['path']));

        if (empty($filteredPaths)) {
            return new WP_Error(401, __('No valid file paths to delete.', 'integrate-dropbox'));
        }

        try {
            $result      = $this->dropbox()->deleteBatch($filteredPaths);
            $apiEntries  = $result->getItems()->toArray();
            $files       = [];

            foreach ($apiEntries as $entry) {
                if ('failure' === ($entry->{'.tag'} ?? null)) {

                    $reason = $entry->failure[$entry->failure['.tag']]['.tag'] ?? 'unknown';

                    $messages = [
                        'not_found'   => __('File or folder not found. It may have already been deleted.', 'integrate-dropbox'),
                        'restricted'  => __('You do not have permission to delete this file.', 'integrate-dropbox'),
                    ];

                    return new WP_Error(
                        'delete_file_error',
                        $messages[$reason]
                            ?? __('Failed to delete the file due to an unknown Dropbox error.', 'integrate-dropbox'),
                        [
                            'dropbox_error' => $reason,
                        ]
                    );
                }

                $file = new File(
                    $entry->getFileData([
                        'accountId' => $this->accountId,
                        'update'    => false
                    ])
                );

                $files[] = $file->getData();
            }

            ModelsFiles::getInstance()->deleteFiles(array_map(fn ($f) => $f['fileKey'], $files));

            return $files;

        } catch (Exception $ex) {
            return new WP_Error(
                'delete_file_error',
                __('Failed to delete file:', 'integrate-dropbox') . $ex->getMessage()
            );
        }
    }

    public function directUploadFile($localPath, $dropboxPath, $params = [])
    {
        $defaultParams = ['mode' => 'add', 'autorename' => true];

        $params = wp_parse_args($params, $defaultParams);

        try {
            $dropboxPath  = ccpidbCleanFolderPath($dropboxPath);
            $apiFile      = $this->dropbox()->upload($localPath, $dropboxPath, $params);
            $file         = new File($apiFile->getFileData(['accountId' => $this->accountId, 'update' => true]));

            return $file->getData();
        } catch (Exception $ex) {
            return new WP_Error(401, esc_html__('Failed to upload file: ', 'integrate-dropbox') . $ex->getMessage());
        }
    }

    public function uploadFile($dropboxPath, $params = [])
    {
        $default_params = ['mode' => 'add', 'autorename' => true];

        $params = wp_parse_args($params, $default_params);

        try {
            $dropboxPath  = ccpidbCleanFolderPath($dropboxPath);
            $apiResult    = $this->dropbox()->getTemporarilyUploadLink($dropboxPath, $params);

            return  $apiResult->getLink();
        } catch (Exception $ex) {
            return new WP_Error(401, esc_html__('Failed to upload file: ', 'integrate-dropbox') . $ex->getMessage());
        }
    }

    public function moveFiles($from_path, $to_path)
    {

        $paramPaths = [];
        foreach ($from_path as $from) {
            if (empty($from)) {
                continue;
            }

            $from = ccpidbCleanFolderPath($from);
            $to   = ccpidbCleanFolderPath($to_path . '/' . basename($from));

            if ($from === $to) {
                continue;
            }

            $paramPaths[] = [
                'from_path' => $from,
                'to_path'   => $to,
            ];
        }

        try {
            $result      = $this->dropbox()->moveBatch($paramPaths);
            $api_entries = $result->getItems()->toArray();
            $files       = [];

            foreach ($api_entries as $api_entry) {

                if ('failure' === $api_entry->{'.tag'}) {
                    continue;
                }

                $newFile                  = new File($api_entry->getFileData(['accountId' => $this->accountId, 'update' => true]));
                $files[$newFile->getId()] = $newFile->getData();
            }

        } catch (Exception $ex) {
            return new WP_Error(401, esc_html__('Failed to move file: ', 'integrate-dropbox') . $ex->getMessage());
        }

        return $files;
    }

    public function copyFiles($from_path, $to_path)
    {

        $filterPaths = [];
        foreach ($from_path as $from) {
            if (empty($from)) {
                continue;
            }

            $from = ccpidbCleanFolderPath($from);
            $to   = ccpidbCleanFolderPath($to_path . '/' . basename($from));

            if ($from === $to) {
                continue;
            }

            $filterPaths[] = [
                'from_path' => $from,
                'to_path'   => $to,
            ];
        }

        if (empty($filterPaths)) {
            return new WP_Error(401, 'No valid from_path and to_path provided for copying.');
        }

        $files       = [];

        try {
            $result      = $this->dropbox()->copyBatch($filterPaths);
            $api_entries = $result->getItems()->toArray();

            foreach ($api_entries as $api_entry) {

                if ('failure' === $api_entry->{'.tag'}) {
                    continue;
                }

                $newFile                  = new File($api_entry->getFileData(['accountId' => $this->accountId, 'update' => true]));
                $files[$newFile->getId()] = $newFile->getData();
            }
        } catch (Exception $ex) {
            return new WP_Error(401, esc_html__('Failed to copy file: ', 'integrate-dropbox') . $ex->getMessage());
        }

        return $files;
    }

    public function renameFile($name, $path)
    {
        $oldPath     = ccpidbCleanFolderPath($path);
        $explodePath = explode('/', $path);
        array_pop($explodePath);
        $parentPath = implode('/', $explodePath);
        $newPath    = ccpidbCleanFolderPath("$parentPath/$name");

        try {
            $apiFile = $this->dropbox()->move($oldPath, $newPath);

            $file    = new File($apiFile->getFileData(['accountId' => $this->accountId, 'update' => true]));
        } catch (Exception $ex) {
            return new WP_Error(401, esc_html__('Failed to rename file: ', 'integrate-dropbox') . $ex->getMessage());
        }

        return $file;
    }

    public function searchFile($query, $path, $options = [])
    {

        $defaultOptions = [
            'max_results'   => 20,
            'file_status'   => 'active', // or 'deleted'
            'filename_only' => false,
        ];

        $options = wp_parse_args($options, $defaultOptions);

        try {
            $result = $this->dropbox()->search($path, $query, ['options' => $options]);

            $apiFiles = $result->getItems()->toArray();

            while ($result->hasMoreItems()) {
                $cursor      = $result->getCursor();
                $result      = $this->dropbox()->search_continue($cursor);
                $apiFiles    = array_merge($apiFiles, $result->getItems()->toArray());
            }
        } catch (Exception $ex) {
            return new WP_Error(401, esc_html__('Failed to search file: ', 'integrate-dropbox') . $ex->getMessage());
        }

        $searchFiles = [];
        foreach ($apiFiles as $apiFile) {
            $fileOrFolder = $apiFile->getMetadata();
            if ($fileOrFolder instanceof \CodeConfig\IDB\Dropbox\Models\FileMetadata || $fileOrFolder instanceof \CodeConfig\IDB\Dropbox\Models\FolderMetadata) {
                $file                        = new File($fileOrFolder->getFileData(['accountId' => $this->accountId, 'update' => true]));
                $searchFiles[$file->getId()] = $file;
            }
        }

        return $searchFiles;
    }

    public function preview($path)
    {

        $path = ccpidbCleanFolderPath($path);

        try {
            $response = $this->dropbox()->preview($path);

            return $response->getContents();
        } catch (Exception $e) {
            return new WP_Error(401, $e->getMessage());
        }
    }

    public function getTemporaryLink($path)
    {
        $path = ccpidbCleanFolderPath($path);

        try {
            $response = $this->dropbox()->getTemporaryLink($path);

            return $response->getLink();
        } catch (Exception $e) {
            return new WP_Error(401, $e->getMessage());
        }
    }

    public function shareLink($path, $params = [])
    {
        $path = ccpidbCleanFolderPath($path);

        $defaults = [
            'audience'         => 'public',
            'access'           => 'viewer',
            'expires'          => null,
            'require_password' => null,
            'link_password'    => null,
        ];

        $params = wp_parse_args($params, $defaults);

        try {
            $response = $this->dropbox()->listSharedLinks($path);

            // return $response->getItems()[0]->getUrl();
        } catch (Exception $e) {
            return new WP_Error(401, $e->getMessage());
        }
    }

    /**
     * Returns a temporarily downloadable link for a file on Dropbox.
     *
     * @param string $path The path to the file on Dropbox.
     *
     * @return string|WP_Error The temporarily downloadable link, or a WP_Error object on failure.
     *
     * @throws WP_Error If the API call fails.
     */
    public function temporaryDownloadLink($path)
    {
        try {
            $response = $this->dropbox()->getTemporaryLink($path);

            return $response->getLink();
        } catch (Exception $e) {
            return new WP_Error(401, $e->getMessage());
        }
    }

    /**
     * Creates a shared link for a file on Dropbox.
     *
     * @param string $id The ID of the file to share.
     * @param array $params Optional parameters for the shared link.
     *                      - audience: string (default: 'public') The audience for the shared link. Can be 'public' or 'team'.
     *                      - access: string (default: 'viewer') The access level for the shared link. Can be 'viewer', 'editor', or 'owner'.
     *                      - expires: string|null (default: null) The timestamp for when the shared link expires.
     *                      - require_password: bool|null (default: null) Whether a password is required to access the shared link.
     *                      - link_password: string|null (default: null) The password required to access the shared link.
     *
     * @return string|array|WP_Error The URL of the shared link, or a WP_Error object on failure.
     *
     */
    public function createSharedLink($path, $params = [])
    {
        $defaults = [
            'audience'             => 'public',
            'access'               => 'viewer',
            'allow_download'       => true,
            'requested_visibility' => 'public',
        ];

        $params   = wp_parse_args($params, $defaults);
        $dropbox  = $this->dropbox();
        $settings = new SharedLinkSettings($params);

        $isManageSharingPermissions = Helpers::getSetting('advanced.manageSharingPermissions', true);

        if (!$isManageSharingPermissions) {
            return $this->getOrFixExistingSharedLink($dropbox, $path);
        }

        try {
            return $dropbox
                ->createSharedLinkWithSettings($path, $settings)
                ->getUrl();
        } catch (DropboxClientException $ex) {

            if (!$this->isSharedLinkAlreadyExistsError($ex)) {
                return new WP_Error(401, $ex->getMessage());
            }

            return $this->getOrFixExistingSharedLink($dropbox, $path);
        }
    }

    private function isSharedLinkAlreadyExistsError(DropboxClientException $ex): bool
    {
        return $ex->getError() === 'shared_link_already_exists'
            || strpos($ex->getErrorSummary(), 'shared_link_already_exists') !== false;
    }

    private function getOrFixExistingSharedLink($dropbox, string $path)
    {
        $links = $dropbox->listSharedLinks($path)->getItems()->all();

        if (empty($links)) {
            return new WP_Error(
                'create_shared_link_error',
                'No shared links found for the specified path.'
            );
        }

        // Prefer an existing downloadable link
        foreach ($links as $link) {
            if (
                ($link instanceof FileLinkMetadata || $link instanceof FolderLinkMetadata) &&
                $link->getLinkPermissions()->getAllowDownload()
            ) {
                return $link->getUrl();
            }
        }

        try {
            $response = $dropbox->modifySharedLinkWithSettings($links[0]->getUrl());

            if ($response instanceof FileLinkMetadata || $response instanceof FolderLinkMetadata) {
                return $response->getUrl();
            }
        } catch (\Throwable $e) {
            return new WP_Error(
                'create_shared_link_error',
                'Failed to modify existing shared link: ' . $e->getMessage()
            );
        }

        return new WP_Error(
            'create_shared_link_error',
            'No downloadable shared links found for the specified path.'
        );
    }

    public function getThumbnail($path, $size = 'w128h128', $format = 'webp')
    {
        try {
            $response = $this->dropbox();

            if (is_wp_error($response)) {
                return $response;
            }

            $response = $response->getThumbnail($path, $size, $format, $mode = 'strict');

            return $response->getContents();
        } catch (Exception $e) {
            return new WP_Error(401, $e->getMessage());
        }
    }

    public function getThumbnails(array $paths, $size = 'w128h128', $format = 'webp')
    {
        $response      = [];

        try {
            $chunk_20 = array_chunk($paths, 20);
            foreach ($chunk_20 as $paths_chunk) {
                $chunk_response = $this->dropbox()->getThumbnailBatch($paths_chunk, $size, $format, $mode = 'strict');

                if (isset($chunk_response['entries'])) {
                    $response = array_merge($response, $chunk_response['entries']);
                }
            }

            return $response;
        } catch (Exception $e) {
            return new WP_Error(401, $e->getMessage());
        }
    }

    public function uploadSessionStart(string $data)
    {
        try {
            //Start the Upload Session
            $sessionId = $this->dropbox()->startUploadSessionRaw($data);

            return $sessionId;
        } catch (Exception $e) {
            return new WP_Error(401, $e->getMessage());
        }
    }

    public function appendUploadSession(string $data, string $sessionId, int $offset, bool $close = false)
    {
        try {
            //Append data to the Upload Session
            $sessionId = $this->dropbox()->appendUploadSessionRaw($data, $sessionId, $offset, $close);

            return $sessionId;
        } catch (Exception $e) {
            return new WP_Error(401, $e->getMessage());
        }
    }

    public function finishUploadSession(string $data, string $sessionId, int $offset, string $dropboxPath, array $params = [])
    {
        $default_params = ['mode' => 'add', 'autorename' => true];

        $params = wp_parse_args($params, $default_params);

        try {
            //Finish the Upload Session
            $apiFile   = $this->dropbox()->finishUploadSessionRaw($data, $sessionId, $offset, $dropboxPath, $params);
            $newFile   = new File($apiFile->getFileData(['accountId' => $this->accountId, 'update' => true]));

            return $newFile->getData();
        } catch (Exception $e) {
            return new WP_Error(401, $e->getMessage());
        }
    }
}
