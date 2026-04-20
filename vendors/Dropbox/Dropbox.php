<?php

namespace CodeConfig\IDB\Dropbox;

use CodeConfig\IDB\Dropbox\Authentication\DropboxAuthHelper;
use CodeConfig\IDB\Dropbox\Authentication\OAuth2Client;
use CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException;
use CodeConfig\IDB\Dropbox\Http\Clients\DropboxHttpClientFactory;
use CodeConfig\IDB\Dropbox\Models\Account;
use CodeConfig\IDB\Dropbox\Models\AccountList;
use CodeConfig\IDB\Dropbox\Models\AsyncJob;
use CodeConfig\IDB\Dropbox\Models\CopyReference;
use CodeConfig\IDB\Dropbox\Models\DeletedMetadata;
use CodeConfig\IDB\Dropbox\Models\File;
use CodeConfig\IDB\Dropbox\Models\FileMetadata;
use CodeConfig\IDB\Dropbox\Models\FolderMetadata;
use CodeConfig\IDB\Dropbox\Models\ModelCollection;
use CodeConfig\IDB\Dropbox\Models\ModelFactory;
use CodeConfig\IDB\Dropbox\Models\Tag;
use CodeConfig\IDB\Dropbox\Models\TemporaryLink;
use CodeConfig\IDB\Dropbox\Models\Thumbnail;
use CodeConfig\IDB\Dropbox\Security\RandomStringGeneratorFactory;
use CodeConfig\IDB\Dropbox\Store\PersistentDataStoreFactory;

/**
 * Dropbox
 */
class Dropbox
{
    /**
     * Uploading a file with the 'uploadFile' method, with the file's
     * size less than this value (~8 MB), the simple `upload` method will be
     * used, if the file size exceed this value (~8 MB), the `startUploadSession`,
     * `appendUploadSession` & `finishUploadSession` methods will be used
     * to upload the file in chunks.
     *
     * @const int
     */
    public const AUTO_CHUNKED_UPLOAD_THRESHOLD = 8000000;

    /**
     * The Chunk Size the file will be
     * split into and uploaded (~4 MB)
     *
     * @const int
     */
    public const DEFAULT_CHUNK_SIZE = 4000000;

    /**
     * Response header containing file metadata
     *
     * @const string
     */
    public const METADATA_HEADER = 'Dropbox-Api-Result';

    /**
     * The Dropbox App
     *
     * @var \CodeConfig\IDB\Dropbox\DropboxApp
     */
    protected $app;

    /**
     * OAuth2 Access Token
     *
     * @var \CodeConfig\IDB\Dropbox\Models\AccessToken
     */
    protected $accessToken;

    /**
     * Dropbox Client
     *
     * @var \CodeConfig\IDB\Dropbox\DropboxClient
     */
    protected $client;

    /**
     * OAuth2 Client
     *
     * @var \CodeConfig\IDB\Dropbox\Authentication\OAuth2Client
     */
    protected $oAuth2Client;

    /**
     * Random String Generator
     *
     * @var \CodeConfig\IDB\Dropbox\Security\RandomStringGeneratorInterface
     */
    protected $randomStringGenerator;

    /**
     * Persistent Data Store
     *
     * @var \CodeConfig\IDB\Dropbox\Store\PersistentDataStoreInterface
     */
    protected $persistentDataStore;

    /**
     * Create a new Dropbox instance
     *
     * @param \CodeConfig\IDB\Dropbox\DropboxApp
     * @param array $config Configuration Array
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     */
    public function __construct(DropboxApp $app, array $config = [])
    {
        //Configuration
        $config = array_merge([
            'http_client_handler'     => null,
            'random_string_generator' => null,
            'persistent_data_store'   => null,
        ], $config);

        //Set the app
        $this->app = $app;

        //Set the access token
        $this->setAccessToken($app->getAccessToken());

        //Make the HTTP Client
        $httpClient = DropboxHttpClientFactory::make($config['http_client_handler']);

        //Make and Set the DropboxClient
        $this->client = new DropboxClient($httpClient);

        //Make and Set the Random String Generator
        $this->randomStringGenerator = RandomStringGeneratorFactory::makeRandomStringGenerator($config['random_string_generator']);

        //Make and Set the Persistent Data Store
        $this->persistentDataStore = PersistentDataStoreFactory::makePersistentDataStore($config['persistent_data_store']);
    }

    /**
     * Get Dropbox Auth Helper
     *
     * @return \CodeConfig\IDB\Dropbox\Authentication\DropboxAuthHelper
     */
    public function getAuthHelper()
    {
        return new DropboxAuthHelper(
            $this->getOAuth2Client(),
            $this->getRandomStringGenerator(),
            $this->getPersistentDataStore()
        );
    }

    /**
     * Get OAuth2Client
     *
     * @return \CodeConfig\IDB\Dropbox\Authentication\OAuth2Client
     */
    public function getOAuth2Client()
    {
        if (! $this->oAuth2Client instanceof OAuth2Client) {
            return new OAuth2Client(
                $this->getApp(),
                $this->getClient(),
                $this->getRandomStringGenerator()
            );
        }

        return $this->oAuth2Client;
    }

    /**
     * Get the Dropbox App.
     *
     * @return \CodeConfig\IDB\Dropbox\DropboxApp Dropbox App
     */
    public function getApp()
    {
        return $this->app;
    }

    /**
     * Get the Client
     *
     * @return \CodeConfig\IDB\Dropbox\DropboxClient
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Get the Random String Generator
     *
     * @return \CodeConfig\IDB\Dropbox\Security\RandomStringGeneratorInterface
     */
    public function getRandomStringGenerator()
    {
        return $this->randomStringGenerator;
    }

    /**
     * Get Persistent Data Store
     *
     * @return \CodeConfig\IDB\Dropbox\Store\PersistentDataStoreInterface
     */
    public function getPersistentDataStore()
    {
        return $this->persistentDataStore;
    }

    /**
     * Get the Metadata for a file or folder
     *
     * @param string $path Path of the file or folder
     * @param array $params Additional Params
     *
     * @return \CodeConfig\IDB\Dropbox\Models\FileMetadata | \CodeConfig\IDB\Dropbox\Models\FolderMetadata
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-get_metadata
     *
     */
    public function getMetadata($path, array $params = [])
    {
        //Root folder is unsupported
        if ($path === '/') {
            throw new DropboxClientException("Metadata for the root folder is unsupported.");
        }

        $default = [
            "include_deleted"                     => false,
            "include_has_explicit_shared_members" => false,
            "include_media_info"                  => true,
            'path'                                => $path
        ];

        $params = wp_parse_args($params, $default);

        //Get File Metadata
        $response = $this->postToAPI('/files/get_metadata', $params);

        //Make and Return the Model
        return $this->makeModelFromResponse($response);
    }

    /**
     * Make a HTTP POST Request to the API endpoint type
     *
     * @param string $endpoint API Endpoint to send Request to
     * @param array $params Request Query Params
     * @param string $accessToken Access Token to send with the Request
     * @param array $headers Request Headers
     *
     * @return \CodeConfig\IDB\Dropbox\DropboxResponse
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     */
    public function postToAPI($endpoint, $params = [], $accessToken = null, $headers = [])
    {
        return $this->sendRequest("POST", $endpoint, 'api', $params, $accessToken, null, $headers);
    }

    /**
     * Make Request to the API
     *
     * @param string $method HTTP Request Method
     * @param string $endpoint API Endpoint to send Request to
     * @param string $endpointType Endpoint type ['api'|'content']
     * @param array $params Request Query Params
     * @param string $accessToken Access Token to send with the Request
     * @param DropboxFile $responseFile Save response to the file
     *
     * @return \CodeConfig\IDB\Dropbox\DropboxResponse
     *
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     */
    public function sendRequest($method, $endpoint, $endpointType = 'api', array $params = [], $accessToken = null, $responseFile = null, $headers = [])
    {
        //Access Token
        $accessToken = $this->getAccessToken() ?: $accessToken;
        if (empty($accessToken)) {
            throw new DropboxClientException("Access Token is required.", 401);
        }

        if ($this->getOAuth2Client()->isAccessTokenExpired()) {
            $accessToken = $this->getAccessToken();
        }

        //Make a DropboxRequest object
        $request = new DropboxRequest($method, $endpoint, $accessToken, $endpointType, $params, $headers);

        //Make a DropboxResponse object if a response should be saved to the file
        $response = $responseFile ? new DropboxResponseToFile($request, $responseFile) : null;

        //Send Request through the DropboxClient
        //Fetch and return the Response
        return $this->getClient()->sendRequest($request, $response);
    }

    /**
     * Get the Access Token.
     *
     * @return \CodeConfig\IDB\Dropbox\Models\AccessToken
     */
    public function getAccessToken()
    {
        return $this->accessToken;
    }

    /**
     * Set the Access Token.
     *
     * @param \CodeConfig\IDB\Dropbox\Models\AccessToken
     *
     * @return \CodeConfig\IDB\Dropbox\Dropbox Dropbox Client
     */
    public function setAccessToken($accessToken)
    {
        $this->accessToken = $accessToken;
        $this->getApp()->setAccessToken($this->accessToken);

        return $this;
    }

    /**
     * Make Model from DropboxResponse
     *
     * @param DropboxResponse $response
     *
     * @return Models\FileMetadata|Models\FolderMetadata|Models\FileLinkMetadata|Models\FolderLinkMetadata|Models\TemporaryLink|Models\MetadataCollection|Models\SearchResults|Models\AsyncJob|Models\DeletedMetadata|Models\Tag|Models\BaseModel
     *
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     */
    public function makeModelFromResponse(DropboxResponse $response)
    {
        //Get the Decoded Body
        $body = $response->getDecodedBody();

        if ($body === null) {
            $body = [];
        }

        //Make and Return the Model
        return ModelFactory::make($body);
    }

    /**
     * Get the contents of a Folder
     *
     * @param string $path Path to the folder. Defaults to root.
     * @param array $params Additional Params
     * @param array $headers Additional Headers
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-list_folder
     *
     * @return \CodeConfig\IDB\Dropbox\Models\MetadataCollection
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     */
    public function listFolder($path = null, $params = [], $headers = [])
    {
        //Specify the root folder as an
        //empty string rather than as "/"
        if ($path === '/') {
            $path = "";
        }

        //Set the path
        $params['path'] = $path;

        //Get File Metadata
        $response = $this->postToAPI('/files/list_folder', $params, null, $headers);

        //Make and Return the Model
        return $this->makeModelFromResponse($response);
    }

    /**
     * Paginate through all files and retrieve updates to the folder,
     * using the cursor retrieved from listFolder or listFolderContinue
     *
     * @param string $cursor The cursor returned by your
     *                       last call to listFolder or listFolderContinue
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-list_folder-continue
     *
     * @return \CodeConfig\IDB\Dropbox\Models\MetadataCollection
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     */
    public function listFolderContinue($cursor)
    {
        $response = $this->postToAPI('/files/list_folder/continue', ['cursor' => $cursor]);

        //Make and Return the Model
        return $this->makeModelFromResponse($response);
    }

    /**
     * Get a cursor for the folder's state.
     *
     * @param string $path Path to the folder. Defaults to root.
     * @param array $params Additional Params
     *
     * @return string The Cursor for the folder's state
     *
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-list_folder-get_latest_cursor
     *
     */
    public function listFolderLatestCursor($path, array $params = [])
    {
        //Specify the root folder as an
        //empty string rather than as "/"
        if ($path === '/') {
            $path = "";
        }

        //Set the path
        $params['path'] = $path;

        //Fetch the cursor
        $response = $this->postToAPI('/files/list_folder/get_latest_cursor', $params);

        //Retrieve the cursor
        $body   = $response->getDecodedBody();
        $cursor = $body['cursor'] ?? false;

        //No cursor returned
        if (! $cursor) {
            throw new DropboxClientException("Could not retrieve cursor. Something went wrong.");
        }

        //Return the cursor
        return $cursor;
    }

    /**
     * Get Revisions of a File
     *
     * @param string $path Path to the file
     * @param array $params Additional Params
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-list_revisions
     *
     * @return \CodeConfig\IDB\Dropbox\Models\ModelCollection
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     */
    public function listRevisions($path, array $params = [])
    {
        //Set the Path
        $params['path'] = $path;

        //Fetch the Revisions
        $response = $this->postToAPI('/files/list_revisions', $params);

        //The file metadata of the entries, returned by this
        //endpoint doesn't include a '.tag' attribute, which
        //is used by the ModelFactory to resolve the correct
        //model. But since we know that revisions returned
        //are file metadata objects, we can explicitly cast
        //them as \CodeConfig\IDB\Dropbox\Models\FileMetadata manually.
        $body             = $response->getDecodedBody();
        $entries          = $body['entries'] ?? [];
        $processedEntries = [];

        foreach ($entries as $entry) {
            $processedEntries[] = new FileMetadata($entry);
        }

        return new ModelCollection($processedEntries);
    }

    /**
     * Search a folder for files/folders
     *
     * @param string $path Path to search
     * @param string $query Search Query
     * @param array $params Additional Params
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-search
     *
     * @return \CodeConfig\IDB\Dropbox\Models\SearchResults
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     */
    public function search($path, $query, array $params = [])
    {
        //Specify the root folder as an
        //empty string rather than as "/"
        if ($path === '/') {
            $path = "";
        }

        //Set the path and query
        $params['options']['path']  = $path;
        $params['query']            = $query;

        //Fetch Search Results
        $response = $this->postToAPI('/files/search_v2', $params);

        //Make and Return the Model
        return $this->makeModelFromResponse($response);
    }

    /**
     * Create a folder at the given path
     *
     * @param string $path Path to create
     * @param boolean $autorename Auto Rename File
     *
     * @return \CodeConfig\IDB\Dropbox\Models\FolderMetadata
     *
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-create_folder
     *
     */
    public function createFolder($path, $autorename = false)
    {
        //Path cannot be null
        if ($path === null) {
            throw new DropboxClientException("Path cannot be null.");
        }

        //Create Folder
        $response = $this->postToAPI('/files/create_folder', ['path' => $path, 'autorename' => $autorename]);

        //Fetch the Metadata
        $body = $response->getDecodedBody();

        //Make and Return the Model
        return new FolderMetadata($body);
    }

    /**
     * Delete a file or folder at the given path
     *
     * @param string $path Path to file/folder to delete
     *
     * @return \CodeConfig\IDB\Dropbox\Models\DeletedMetadata
     *
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-delete
     *
     */
    public function delete($path)
    {
        //Path cannot be null
        if ($path === null) {
            throw new DropboxClientException("Path cannot be null.");
        }

        //Delete
        $response = $this->postToAPI('/files/delete_v2', ['path' => $path]);
        $body     = $response->getDecodedBody();

        //Response doesn't have Metadata
        if (! isset($body['metadata']) || ! is_array($body['metadata'])) {
            throw new DropboxClientException("Invalid Response.");
        }

        return new DeletedMetadata($body['metadata']);
    }

    /**
     * Move a file or folder to a different location
     *
     * @param string $fromPath Path to be moved
     * @param string $toPath Path to be moved to
     *
     * @return \CodeConfig\IDB\Dropbox\Models\DeletedMetadata|\CodeConfig\IDB\Dropbox\Models\FileMetadata
     *
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-move
     *
     */
    public function move($fromPath, $toPath)
    {
        //From and To paths cannot be null
        if ($fromPath === null || $toPath === null) {
            throw new DropboxClientException("From and To paths cannot be null.");
        }

        //Response
        $response = $this->postToAPI('/files/move', ['from_path' => $fromPath, 'to_path' => $toPath]);

        //Make and Return the Model
        return $this->makeModelFromResponse($response);
    }

    /**
     * Copy a file or folder to a different location
     *
     * @param string $fromPath Path to be copied
     * @param string $toPath Path to be copied to
     *
     * @return \CodeConfig\IDB\Dropbox\Models\DeletedMetadata|\CodeConfig\IDB\Dropbox\Models\FileMetadata
     *
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-copy
     *
     */
    public function copy($fromPath, $toPath)
    {
        //From and To paths cannot be null
        if (is_null($fromPath) || is_null($toPath)) {
            throw new DropboxClientException("From and To paths cannot be null.");
        }

        //Response
        $response = $this->postToAPI('/files/copy', ['from_path' => $fromPath, 'to_path' => $toPath]);

        //Make and Return the Model
        return $this->makeModelFromResponse($response);
    }

    /**
     * Restore a file to the specific version
     *
     * @param string $path Path to the file to restore
     * @param string $rev Revision to store for the file
     *
     * @return \CodeConfig\IDB\Dropbox\Models\DeletedMetadata|\CodeConfig\IDB\Dropbox\Models\FileMetadata|\CodeConfig\IDB\Dropbox\Models\FolderMetadata
     *
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-restore
     *
     */
    public function restore($path, $rev)
    {
        //Path and Revision cannot be null
        if (is_null($path) || is_null($rev)) {
            throw new DropboxClientException("Path and Revision cannot be null.");
        }

        //Response
        $response = $this->postToAPI('/files/restore', ['path' => $path, 'rev' => $rev]);

        //Fetch the Metadata
        $body = $response->getDecodedBody();

        //Make and Return the Model
        return new FileMetadata($body);
    }

    /**
     * Get Copy Reference
     *
     * @param string $path Path to the file or folder to get a copy reference to
     *
     * @return \CodeConfig\IDB\Dropbox\Models\CopyReference
     *
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-copy_reference-get
     *
     */
    public function getCopyReference($path)
    {
        //Path cannot be null
        if ($path === null) {
            throw new DropboxClientException("Path cannot be null.");
        }

        //Get Copy Reference
        $response = $this->postToAPI('/files/copy_reference/get', ['path' => $path]);
        $body     = $response->getDecodedBody();

        //Make and Return the Model
        return new CopyReference($body);
    }

    /**
     * Save Copy Reference
     *
     * @param string $path Path to the file or folder to get a copy reference to
     * @param string $copyReference Copy reference returned by getCopyReference
     *
     * @return \CodeConfig\IDB\Dropbox\Models\FileMetadata|\CodeConfig\IDB\Dropbox\Models\FolderMetadata
     *
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-copy_reference-save
     *
     */
    public function saveCopyReference($path, $copyReference)
    {
        //Path and Copy Reference cannot be null
        if ($path === null || $copyReference === null) {
            throw new DropboxClientException("Path and Copy Reference cannot be null.");
        }

        //Save Copy Reference
        $response = $this->postToAPI('/files/copy_reference/save', ['path' => $path, 'copy_reference' => $copyReference]);
        $body     = $response->getDecodedBody();

        //Response doesn't have Metadata
        if (! isset($body['metadata']) || ! is_array($body['metadata'])) {
            throw new DropboxClientException("Invalid Response.");
        }

        //Make and return the Model
        return ModelFactory::make($body['metadata']);
    }

    /**
     * Get a temporary link to stream contents of a file
     *
     * @param string $path Path to the file you want a temporary link to
     *
     * https://www.dropbox.com/developers/documentation/http/documentation#files-get_temporary_link
     *
     * @return \CodeConfig\IDB\Dropbox\Models\TemporaryLink
     *
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     */
    public function getTemporaryLink($path)
    {
        //Path cannot be null
        if ($path === null) {
            throw new DropboxClientException("Path cannot be null.");
        }

        //Get Temporary Link
        $response = $this->postToAPI('/files/get_temporary_link', ['path' => $path]);

        //Make and Return the Model
        return $this->makeModelFromResponse($response);
    }

    /**
     * Save a specified URL into a file in user's Dropbox
     *
     * @param string $path Path where the URL will be saved
     * @param string $url URL to be saved
     *
     * @return string Async Job ID
     *
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-save_url
     *
     */
    public function saveUrl($path, $url)
    {
        //Path and URL cannot be null
        if ($path === null || $url === null) {
            throw new DropboxClientException("Path and URL cannot be null.");
        }

        //Save URL
        $response = $this->postToAPI('/files/save_url', ['path' => $path, 'url' => $url]);
        $body     = $response->getDecodedBody();

        if (! isset($body['async_job_id'])) {
            throw new DropboxClientException("Could not retrieve Async Job ID.");
        }

        //Return the Async Job ID
        return $body['async_job_id'];
    }

    /**
     * Save a specified URL into a file in user's Dropbox
     *
     * @param $asyncJobId
     *
     * @return \CodeConfig\IDB\Dropbox\Models\FileMetadata|string Status (failed|in_progress) or FileMetadata (if complete)
     *
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     *
     * @link     https://www.dropbox.com/developers/documentation/http/documentation#files-save_url-check_job_status
     *
     */
    public function checkJobStatus($asyncJobId)
    {
        //Async Job ID cannot be null
        if (is_null($asyncJobId)) {
            throw new DropboxClientException("Async Job ID cannot be null.");
        }

        //Get Job Status
        $response = $this->postToAPI('/files/save_url/check_job_status', ['async_job_id' => $asyncJobId]);
        $body     = $response->getDecodedBody();

        //Status
        $status = isset($body['.tag']) ? $body['.tag'] : '';

        //If status is complete
        if ($status === 'complete') {
            return new FileMetadata($body);
        }

        //Return the status
        return $status;
    }

    /**
     * Upload a File to Dropbox
     *
     * @param string|DropboxFile $dropboxFile DropboxFile object or Path to file
     * @param string $path Path to upload the file to
     * @param array $params Additional Params
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-upload
     *
     * @return \CodeConfig\IDB\Dropbox\Models\FileMetadata
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     */
    public function upload($dropboxFile, $path, array $params = [])
    {
        //Make Dropbox File
        $dropboxFile = $this->makeDropboxFile($dropboxFile);

        //If the file is larger than the Chunked Upload Threshold
        if ($dropboxFile->getSize() > static::AUTO_CHUNKED_UPLOAD_THRESHOLD) {
            //Upload the file in sessions/chunks
            return $this->uploadChunked($dropboxFile, $path, null, null, $params);
        }

        //Simple file upload
        return $this->simpleUpload($dropboxFile, $path, $params);
    }

    /**
     * Make DropboxFile Object
     *
     * @param string|DropboxFile $dropboxFile DropboxFile object or Path to file
     * @param int $maxLength Max Bytes to read from the file
     * @param int $offset Seek to specified offset before reading
     * @param string $mode The type of access
     *
     * @return \CodeConfig\IDB\Dropbox\DropboxFile
     */
    public function makeDropboxFile($dropboxFile, $maxLength = null, $offset = null, $mode = DropboxFile::MODE_READ)
    {
        //Uploading file by file path
        if (! $dropboxFile instanceof DropboxFile) {
            //Create a DropboxFile Object
            $dropboxFile = new DropboxFile($dropboxFile, $mode);
        } elseif ($mode !== $dropboxFile->getMode()) {
            //Reopen the file with expected mode
            $dropboxFile->close();
            $dropboxFile = new DropboxFile($dropboxFile->getFilePath(), $mode);
        }

        if (! is_null($offset)) {
            $dropboxFile->setOffset($offset);
        }

        if (! is_null($maxLength)) {
            $dropboxFile->setMaxLength($maxLength);
        }

        //Return the DropboxFile Object
        return $dropboxFile;
    }

    /**
     * Upload file in sessions/chunks
     *
     * @param string|DropboxFile $dropboxFile DropboxFile object or Path to file
     * @param string $path Path to save the file to, on Dropbox
     * @param int $fileSize The size of the file
     * @param int $chunkSize The amount of data to upload in each chunk
     * @param array $params Additional Params
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-upload_session-start
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-upload_session-finish
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-upload_session-append_v2
     *
     * @return \CodeConfig\IDB\Dropbox\Models\FileMetadata
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     */
    public function uploadChunked($dropboxFile, $path, $fileSize = null, $chunkSize = null, array $params = [])
    {
        //Make Dropbox File
        $dropboxFile = $this->makeDropboxFile($dropboxFile);

        //No file size specified explicitly
        if ($fileSize === null) {
            $fileSize = $dropboxFile->getSize();
        }

        //No chunk size specified, use default size
        if ($chunkSize === null) {
            $chunkSize = static::DEFAULT_CHUNK_SIZE;
        }

        //If the fileSize is smaller
        //than the chunk size, we'll
        //make the chunk size relatively
        //smaller than the file size
        if ($fileSize <= $chunkSize) {
            $chunkSize = intval($fileSize / 2);
        }

        //Start the Upload Session with the file path
        //since the DropboxFile object will be created
        //again using the new chunk size.
        $sessionId = $this->startUploadSession($dropboxFile->getFilePath(), $chunkSize);

        //Uploaded
        $uploaded = $chunkSize;

        //Remaining
        $remaining = $fileSize - $chunkSize;
        $optionKey = 'ccpidb_upload_status';

        set_transient($optionKey, 0, HOUR_IN_SECONDS);

        //While the remaining bytes are
        //more than the chunk size, append
        //the chunk to the upload session.
        while ($remaining > $chunkSize) {
            //Append the next chunk to the Upload session
            $sessionId = $this->appendUploadSession($dropboxFile, $sessionId, $uploaded, $chunkSize);

            //Update remaining and uploaded
            $uploaded  = $uploaded + $chunkSize;
            $remaining = $remaining - $chunkSize;
            set_transient($optionKey, intval(($uploaded / $fileSize) * 100), HOUR_IN_SECONDS);
        }

        //Finish the Upload Session and return the Uploaded File Metadata
        set_transient($optionKey, intval(($uploaded / $fileSize) * 100), HOUR_IN_SECONDS);
        $finished = $this->finishUploadSession($dropboxFile, $sessionId, $uploaded, $remaining, $path, $params);

        set_transient($optionKey, 100, HOUR_IN_SECONDS);

        return $finished;
    }

    /**
     * Start an Upload Session
     *
     * @param string|DropboxFile $dropboxFile DropboxFile object or Path to file
     * @param int $chunkSize Size of file chunk to upload
     * @param boolean $close Closes the session for "appendUploadSession"
     *
     * @return string Unique identifier for the upload session
     *
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-upload_session-start
     *
     */
    public function startUploadSession($dropboxFile, $chunkSize = -1, $close = false)
    {
        //Make Dropbox File with the given chunk size
        $dropboxFile = $this->makeDropboxFile($dropboxFile, $chunkSize);

        //Set the close param
        $params = [
            'close' => $close ? true : false,
            'file'  => $dropboxFile,
        ];

        //Upload File
        $file = $this->postToContent('/files/upload_session/start', $params);
        $body = $file->getDecodedBody();

        //Cannot retrieve Session ID
        if (! isset($body['session_id'])) {
            throw new DropboxClientException("Could not retrieve Session ID.");
        }

        //Return the Session ID
        return $body['session_id'];
    }

    public function startUploadSessionRaw(string $data, bool $close = false)
    {

        //Set the close param
        $params = [
            'close' => $close ? true : false,
            'raw'   => $data,
        ];

        //Upload File
        $file = $this->postToContent('/files/upload_session/start', $params);
        $body = $file->getDecodedBody();

        //Cannot retrieve Session ID
        if (! isset($body['session_id'])) {
            throw new DropboxClientException("Could not retrieve Session ID.");
        }

        //Return the Session ID
        return $body['session_id'];
    }

    /**
     * Make a HTTP POST Request to the Content endpoint type
     *
     * @param string $endpoint Content Endpoint to send Request to
     * @param array $params Request Query Params
     * @param string $accessToken Access Token to send with the Request
     * @param DropboxFile $responseFile Save response to the file
     *
     * @return \CodeConfig\IDB\Dropbox\DropboxResponse
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     */
    public function postToContent($endpoint, array $params = [], $accessToken = null, $responseFile = null)
    {
        return $this->sendRequest("POST", $endpoint, 'content', $params, $accessToken, $responseFile);
    }

    /**
     * Append more data to an Upload Session
     *
     * @param string $data Raw data to append
     * @param string $sessionId Session ID returned by `startUploadSession`
     * @param int $offset The amount of data that has been uploaded so far
     * @param boolean $close Closes the session for futher "appendUploadSession" calls
     *
     * @return string Unique identifier for the upload session
     *
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-upload_session-append_v2
     *
     */
    public function appendUploadSessionRaw(string $data, string $sessionId, int $offset, bool $close = false)
    {

        //Session ID, offset, chunkSize and path cannot be null
        if ($sessionId === null || $offset === null) {
            throw new DropboxClientException("Session ID and offset cannot be null");
        }

        $params = [
            'raw'              => $data,
            'cursor'           => ['session_id' => $sessionId, 'offset' => $offset],
            'close'            => $close ? true : false,
            'validateResponse' => false,
        ];

        //Upload File
        $this->postToContent('/files/upload_session/append_v2', $params);

        //Make and Return the Model
        return $sessionId;
    }

    public function appendUploadSession($dropboxFile, $sessionId, $offset, $chunkSize, $close = false)
    {
        //Make Dropbox File
        $dropboxFile = $this->makeDropboxFile($dropboxFile, $chunkSize, $offset);

        //Session ID, offset, chunkSize and path cannot be null
        if (is_null($sessionId) || is_null($offset) || is_null($chunkSize)) {
            throw new DropboxClientException("Session ID, offset and chunk size cannot be null");
        }

        $params = [];

        //Set the File
        $params['file'] = $dropboxFile;

        //Set the Cursor: Session ID and Offset
        $params['cursor'] = ['session_id' => $sessionId, 'offset' => $offset];

        //Set the close param
        $params['close'] = $close ? true : false;

        //Since this endpoint doesn't have
        //any return values, we'll disable the
        //response validation for this request.
        $params['validateResponse'] = false;

        //Upload File
        $this->postToContent('/files/upload_session/append_v2', $params);

        //Make and Return the Model
        return $sessionId;
    }

    /**
     * Finish an upload session and save the uploaded data to the given file path
     *
     * @param string|DropboxFile $dropboxFile DropboxFile object or Path to file
     * @param string $sessionId Session ID returned by `startUploadSession`
     * @param int $offset The amount of data that has been uploaded so far
     * @param int $remaining The amount of data that is remaining
     * @param string $path Path to save the file to, on Dropbox
     * @param array $params Additional Params
     *
     * @return \CodeConfig\IDB\Dropbox\Models\FileMetadata
     *
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-upload_session-finish
     *
     */
    public function finishUploadSession($dropboxFile, $sessionId, $offset, $remaining, $path, array $params = [])
    {
        //Make Dropbox File
        $dropboxFile = $this->makeDropboxFile($dropboxFile, $remaining, $offset);

        //Session ID, offset, remaining and path cannot be null
        if ($sessionId === null || $path === null || $offset === null || $remaining === null) {
            throw new DropboxClientException("Session ID, offset, remaining and path cannot be null");
        }

        $queryParams = [];

        //Set the File
        $queryParams['file'] = $dropboxFile;

        //Set the Cursor: Session ID and Offset
        $queryParams['cursor'] = ['session_id' => $sessionId, 'offset' => $offset];

        //Set the path
        $params['path'] = $path;
        //Set the Commit
        $queryParams['commit'] = $params;

        //Upload File
        $file = $this->postToContent('/files/upload_session/finish', $queryParams);
        $body = $file->getDecodedBody();

        //Make and Return the Model
        return new FileMetadata($body);
    }

    public function finishUploadSessionRaw(string $data, string $sessionId, int $offset, string $path, array $params = [])
    {
        //Session ID, offset, remaining and path cannot be null
        if ($sessionId === null || $path === null || $offset === null) {
            throw new DropboxClientException("Session ID, offset, remaining and path cannot be null");
        }

        $defaultCommit = [
            'autorename'      => true,
            'mode'            => 'add',
            'mute'            => false,
            'strict_conflict' => false,
        ];

        $commit = wp_parse_args($params, $defaultCommit);

        $commit['path'] = $path;

        $QParams = [
            'raw'    => $data,
            'cursor' => ['session_id' => $sessionId, 'offset' => $offset],
            'commit' => $commit,
        ];

        //Upload File
        $file = $this->postToContent('/files/upload_session/finish', $QParams);
        $body = $file->getDecodedBody();

        //Make and Return the Model
        return new FileMetadata($body);
    }

    /**
     * Upload a File to Dropbox in a single request
     *
     * @param string|DropboxFile $dropboxFile DropboxFile object or Path to file
     * @param string $path Path to upload the file to
     * @param array $params Additional Params
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-upload
     *
     * @return \CodeConfig\IDB\Dropbox\Models\FileMetadata
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     */
    public function simpleUpload($dropboxFile, $path, array $params = [])
    {
        //Make Dropbox File
        $dropboxFile = $this->makeDropboxFile($dropboxFile);

        //Set the path and file
        $params['path'] = $path;
        $params['file'] = $dropboxFile;

        //Upload File
        $file = $this->postToContent('/files/upload', $params);
        $body = $file->getDecodedBody();

        //Make and Return the Model
        return new FileMetadata($body);
    }

    /**
     * Get a thumbnail for an image
     *
     * @param string $path Path to the file you want a thumbnail to
     * @param string $size Size for the thumbnail image ['thumb','small','medium','large','huge']
     * @param string $format Format for the thumbnail image ['jpeg'|'png']
     *
     * @return \CodeConfig\IDB\Dropbox\Models\Thumbnail
     *
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-get_thumbnail
     *
     */
    public function getThumbnail($path, $size = 'w256h256', $format = 'webp', $mode = 'strict')
    {
        //Path cannot be null
        if (is_null($path)) {
            throw new DropboxClientException("Path cannot be null.");
        }

        //Invalid Format
        if (! in_array($format, ['jpeg', 'png', 'webp'])) {
            throw new DropboxClientException("Invalid format. Must either be 'jpeg', 'png', or 'webp'.");
        }

        //Get Thumbnail
        $response = $this->postToContent('/files/get_thumbnail', ['path' => $path, 'format' => $format, 'size' => $size]);

        //Get file metadata from response headers
        $metadata = $this->getMetadataFromResponseHeaders($response);

        //File Contents
        $contents = $response->getBody();

        //Make and return a Thumbnail model
        return new Thumbnail($metadata, $contents);
    }

    public function getThumbnailBatch($paths, $size = 'w256h256', $format = 'webp', $mode = 'strict')
    {
        //Path cannot be null
        if (is_null($paths) || ! is_array($paths) || count($paths) == 0) {
            throw new DropboxClientException("Path cannot be null.");
        }

        //Invalid Format
        if (! in_array($format, ['jpeg', 'png', 'webp'])) {
            throw new DropboxClientException("Invalid format. Must either be 'jpeg', 'png', or 'webp'.");
        }

        $params = array_map(fn ($path) => [
            'path'    => $path,
            'format'  => $format,
            'size'    => $size,
            "mode"    => $mode,
            "quality" => "quality_80",
        ], $paths);

        //Get Thumbnail
        $response = $this->postToContent('/files/get_thumbnail_batch', [ 'entries' => $params]);

        //Get file metadata from response headers
        $metadata = $this->getMetadataFromResponseHeaders($response);

        //File Contents
        return $response->getDecodedBody();
    }

    /**
     * Get thumbnail size
     *
     * @param string $size Thumbnail Size
     *
     * @return string
     */
    protected function getThumbnailSize($size)
    {
        $thumbnailSizes = [
            'thumb'  => 'w32h32',
            'small'  => 'w64h64',
            'medium' => 'w128h128',
            'large'  => 'w640h480',
            'huge'   => 'w1024h768',
        ];

        return $thumbnailSizes[$size] ?? $thumbnailSizes['small'];
    }

    /**
     * Get metadata from response headers
     *
     * @param DropboxResponse $response
     *
     * @return array
     */
    protected function getMetadataFromResponseHeaders(DropboxResponse $response)
    {
        //Response Headers
        $headers = $response->getHeaders();

        //Empty metadata for when
        //metadata isn't returned
        $metadata = [];

        //If metadata is available
        if (isset($headers[static::METADATA_HEADER])) {
            //File Metadata
            $data = $headers[static::METADATA_HEADER];

            //The metadata is present in the first index
            //of the metadata response header array
            if (is_array($data) && isset($data[0])) {
                $data = $data[0];
            }

            //Since the metadata is returned as a json string
            //it needs to be decoded into an associative array
            $metadata = json_decode((string) $data, true);
        }

        //Return the metadata
        return $metadata;
    }

    /**
     * Download a File
     *
     * @param string $path Path to the file you want to download
     * @param null|string|DropboxFile $dropboxFile DropboxFile object or Path to target file
     *
     * @return \CodeConfig\IDB\Dropbox\Models\File
     *
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-download
     *
     */
    public function download($path, $dropboxFile = null)
    {
        //Path cannot be null
        if (is_null($path)) {
            throw new DropboxClientException("Path cannot be null.");
        }

        //Make Dropbox File if target is specified
        $dropboxFile = $dropboxFile ? $this->makeDropboxFile($dropboxFile, null, null, DropboxFile::MODE_WRITE) : null;

        //Download File
        $response = $this->postToContent('/files/download', ['path' => $path], null, $dropboxFile);

        //Get file metadata from response headers
        $metadata = $this->getMetadataFromResponseHeaders($response);

        //File Contents
        $contents = $dropboxFile ? $this->makeDropboxFile($dropboxFile) : $response->getBody();

        //Make and return a File model
        return new File($metadata, $contents);
    }
    public function downloadZip($path)
    {
        //Path cannot be null
        if (is_null($path)) {
            throw new DropboxClientException("Path cannot be null.");
        }

        //Download File
        $response = $this->postToContent('/files/download_zip', ['path' => $path], null);

        $body = $response->getBody();

        //Make and return a File model
        return base64_encode($body);
    }

    /**
     * Get Current Account
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#users-get_current_account
     *
     * @return \CodeConfig\IDB\Dropbox\Models\Account
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     */
    public function getCurrentAccount()
    {
        //Get current account
        $response = $this->postToAPI('/users/get_current_account', []);
        $body     = $response->getDecodedBody();

        //Make and return the model
        return new Account($body);
    }

    /**
     * Get Account
     *
     * @param string $account_id Account ID of the account to get details for
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#users-get_account
     *
     * @return \CodeConfig\IDB\Dropbox\Models\Account
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     */
    public function getAccount($account_id)
    {
        //Get account
        $response = $this->postToAPI('/users/get_account', ['account_id' => $account_id]);
        $body     = $response->getDecodedBody();

        //Make and return the model
        return new Account($body);
    }

    /**
     * Get Multiple Accounts in one call
     *
     * @param array $account_ids IDs of the accounts to get details for
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#users-get_account_batch
     *
     * @return \CodeConfig\IDB\Dropbox\Models\AccountList
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     */
    public function getAccounts(array $account_ids = [])
    {
        //Get account
        $response = $this->postToAPI('/users/get_account_batch', ['account_ids' => $account_ids]);
        $body     = $response->getDecodedBody();

        //Make and return the model
        return new AccountList($body);
    }

    /**
     * Get Space Usage for the current user's account
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#users-get_space_usage
     *
     * @return array
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     */
    public function getSpaceUsage()
    {
        //Get space usage
        $response = $this->postToAPI('/users/get_space_usage', []);
        $body     = $response->getDecodedBody();

        //Return the decoded body
        return $body;
    }

    /**
     * Create a shared link with custom settings.
     *
     * @param string $path The path to be shared by the shared link
     * @param Models\SharedLinkSettings $settings the requested settings for the newly created shared link This field is optional
     *
     * @see https://www.dropbox.com/developers/documentation/http/documentation#files-create_folder
     *
     * @return \CodeConfig\IDB\Dropbox\Models\FileLinkMetadata|\CodeConfig\IDB\Dropbox\Models\FolderLinkMetadata
     */
    public function createSharedLinkWithSettings($path, $settings = [])
    {
        // Path cannot be null
        if ($path === null) {
            throw new DropboxClientException('Path cannot be null.');
        }
        // Create Folder
        $response = $this->postToAPI('/sharing/create_shared_link_with_settings', ['path' => $path, 'settings' => $settings]);

        // Make and Return the Model
        return $this->makeModelFromResponse($response);
    }

    /**
     * Create a shared link with custom settings.
     *
     * @param string $path The path to be shared by the shared link
     * @param Models\SharedLinkSettings $settings the requested settings for the newly created shared link This field is optional
     *
     * @see https://www.dropbox.com/developers/documentation/http/documentation#files-create_folder
     *
     * @return \CodeConfig\IDB\Dropbox\Models\FileLinkMetadata|\CodeConfig\IDB\Dropbox\Models\FolderLinkMetadata
     */
    public function modifySharedLinkWithSettings($url, $settings = [], $remove_expiration = false)
    {
        // Path cannot be null
        if (is_null($url)) {
            throw new DropboxClientException('Path cannot be null.');
        }

        $defaults = [
            'access'               => 'viewer',
            'allow_download'       => true,
            'audience'             => 'public',
            'requested_visibility' => 'public',
        ];

        $settings = wp_parse_args($settings, $defaults);

        // Create Folder
        $response = $this->postToAPI('/sharing/modify_shared_link_settings', ['url' => $url, 'settings' => $settings, 'remove_expiration' => $remove_expiration]);

        // Make and Return the Model
        return $this->makeModelFromResponse($response);
    }

    public function shareFolder($path, $params = [])
    {
        // Path cannot be null
        if ($path === null) {
            throw new DropboxClientException('Path cannot be null.');

        }
        $defaults = [
            'access_inheritance' => 'inherit',
            'acl_update_policy'  => 'editors',
            'force_async'        => false,
            'member_policy'      => 'anyone',
            'shared_link_policy' => 'anyone',
        ];
        $params = array_merge($defaults, $params, ['path' => $path]);

        // Create Folder
        $response = $this->postToAPI('/sharing/share_folder', $params);

        // Make and Return the Model
        return $this->makeModelFromResponse($response);
    }

    /**
     * List shared links of this user.
     *
     * @param string $path Path to the folder. Defaults to root.
     * @param string $cursor the cursor returned by your last call to list_shared_links
     * @param array $params Additional Params
     *
     * @see https://www.dropbox.com/developers/documentation/http/documentation#sharing-list_shared_links
     *
     * @return \CodeConfig\IDB\Dropbox\Models\MetadataCollection
     */
    public function listSharedLinks($path = null, $cursor = null, array $params = ['direct_only' => true])
    {
        // Specify the root folder as an
        // empty string rather than as "/"
        if ('/' === $path) {
            $path = '';
        }

        // Set the path
        if (! empty($path)) {
            $params['path'] = $path;
        } elseif (! empty($cursor)) {
            $params['cursor'] = $cursor;
        }

        // Get File Metadata
        $response = $this->postToAPI('/sharing/list_shared_links', $params);

        // Make and Return the Model
        return $this->makeModelFromResponse($response);
    }

    /**
     * Preview a File.
     *
     * @param string $path Path to the file you want to download
     *
     * @see https://www.dropbox.com/developers/documentation/http/documentation#files-get_preview
     *
     * @return \CodeConfig\IDB\Dropbox\Models\File
     */
    public function preview($path)
    {
        // Path cannot be null
        if (is_null($path)) {
            throw new DropboxClientException('Path cannot be null.');
        }

        // Download File
        $response = $this->postToContent('/files/get_preview', ['path' => $path]);

        // Get file metadata from response headers
        $metadata = $this->getMetadataFromResponseHeaders($response);

        // File Contents
        $contents = $response->getBody();

        // Make and return a File model
        return new File($metadata, $contents);
    }

    /**
     * Copy a file or folder to a different location.
     *
     * @param array $params Entries to be copied
     *
     * @see https://www.dropbox.com/developers/documentation/http/documentation#files-copy_batch
     *
     * @return Models\ModelCollection
     */
    public function copyBatch($params)
    {

        if ($params === null) {
            throw new DropboxClientException('Entries cannot be null.');
        }

        // Response
        $response = $this->postToAPI('/files/copy_batch_v2', ['entries' => $params]);

        // Make and Return the Model
        return $this->waitForAsyncRequest($response, '/files/copy_batch/check_v2');

    }

    public function waitForAsyncRequest($raw_response, $request_url, $async_job_id = null)
    {
        $response = $this->makeModelFromResponse($raw_response);

        if (! ($response instanceof AsyncJob) && ! ($response instanceof Tag)) {
            return $response;
        }

        if ($response instanceof Tag && 'in_progress' !== $response->getTag()) {
            return $response;
        }

        if ($response instanceof AsyncJob && empty($async_job_id)) {
            $async_job_id = $response->getAsyncJobId();
        }

        usleep(1000000);
        $raw_response = $this->postToAPI($request_url, ['async_job_id' => $async_job_id]);

        return $this->waitForAsyncRequest($raw_response, $request_url, $async_job_id);
    }

    /**
     * Move multiple files or folders to different locations at once.
     *
     * @param array $entries Entries to be moved
     * @param mixed $async
     *
     * @see https://www.dropbox.com/developers/documentation/http/documentation#files-move_batch
     *
     * @return \CodeConfig\IDB\Dropbox\Models\ModelCollection
     */
    public function moveBatch(array $params, $async = true)
    {
        if ($params === null) {
            throw new DropboxClientException('From and To paths cannot be null.');
        }

        // Response
        $response = $this->postToAPI('/files/move_batch_v2', ['entries' => $params]);


        if (false === $async) {
            return $this->makeModelFromResponse($response);
        }

        return $this->waitForAsyncRequest($response, '/files/move_batch/check_v2');
    }

    /**
     * Delete a file or folder at the given path.
     *
     * @param array $entries Entries to be deleted
     * @param mixed $async
     *
     * @see https://www.dropbox.com/developers/documentation/http/documentation#files-delete_batch
     *
     * @return Models\ModelCollection
     *
     * @throws DropboxClientException
     */
    public function deleteBatch($params, $async = true)
    {
        // Path cannot be null
        if ($params === null) {
            throw new DropboxClientException('Entries cannot be null.');
        }

        // Delete
        $response = $this->postToAPI('/files/delete_batch', ['entries' => $params]);

        // Make and Return the Model
        if (false === $async) {
            return $this->makeModelFromResponse($response);
        }

        return $this->waitForAsyncRequest($response, '/files/delete_batch/check');
    }

    /**
     * Fetches the next page of search results returned from /search_v2.
     *
     * @param string $cursor The cursor returned by your last call to search
     *
     * @see https://www.dropbox.com/developers/documentation/http/documentation?oref=e#files-search-continue:2
     *
     * @return \CodeConfig\IDB\Dropbox\Models\SearchResults
     */
    public function search_continue($cursor)
    {
        // Fetch Search Results
        $response = $this->postToAPI('/files/search/continue_v2', ['cursor' => $cursor]);

        // Make and Return the Model
        return $this->makeModelFromResponse($response);
    }

    /**
     * Get a one-time use temporary upload link to upload a file to a Dropbox location.
     *
     * @param string $path Path to upload the file to
     * @param array $commit_info Additional Params
     * @param array $duration how long before this link expires, in seconds
     * @param mixed $origin
     *
     * @see https://www.dropbox.com/developers/documentation/http/documentation#files-upload
     *
     * @return \CodeConfig\IDB\Dropbox\Models\TemporaryLink
     */
    public function getTemporarilyUploadLink($path, $commit_info = [], $duration = 3600, $origin = '')
    {

        $default_commit_info = [
            "autorename"      => true,
            "mode"            => "add",
            "mute"            => false,
            "strict_conflict" => false
        ];

        $commit_info = wp_parse_args($commit_info, $default_commit_info);

        $params                        = [];
        $params['commit_info']         = $commit_info;
        $params['commit_info']['path'] = $path;
        $params['duration']            = $duration;

        $request = new DropboxRequest('POST', '/files/get_temporary_upload_link', $this->getAccessToken(), ' api', $params);

        $request->setHeaders(['Origin' => $origin]);
        $result = $this->getClient()->sendRequest($request);

        $body = $result->getDecodedBody();

        return new TemporaryLink($body);
    }

}
