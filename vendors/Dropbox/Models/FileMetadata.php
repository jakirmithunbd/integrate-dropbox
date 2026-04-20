<?php

namespace CodeConfig\IDB\Dropbox\Models;

use CodeConfig\IDB\Utils\Helpers;
use CodeConfig\IDB\Utils\MimeTypeManager;

class FileMetadata extends BaseModel
{
    /**
     * A unique identifier of the file
     *
     * @var string
     */
    protected $id;

    /**
     * Object type
     *
     * @var string
     */
    protected $tag;

    /**
     * The last component of the path (including extension).
     *
     * @var string
     */
    protected $name;

    /**
     * A unique identifier for the current revision of a file.
     * This field is the same rev as elsewhere in the API and
     * can be used to detect changes and avoid conflicts.
     *
     * @var string
     */
    protected $rev;

    /**
     * The file size in bytes.
     *
     * @var int
     */
    protected $size;

    /**
     * The lowercased full path in the user's Dropbox.
     *
     * @var string
     */
    protected $path_lower;

    /**
     * Additional information if the file is a photo or video.
     *
     * @var \CodeConfig\IDB\Dropbox\Models\MediaInfo
     */
    protected $media_info;

    /**
     * Set if this file is contained in a shared folder.
     *
     * @var \CodeConfig\IDB\Dropbox\Models\FileSharingInfo
     */
    protected $sharing_info;

    /**
     * The cased path to be used for display purposes only.
     *
     * @var string
     */
    protected $path_display;

    /**
     * For files, this is the modification time set by the
     * desktop client when the file was added to Dropbox.
     *
     * @var string
     */
    protected $client_modified;

    /**
     * The last time the file was modified on Dropbox.
     *
     * @var string
     */
    protected $server_modified;

    /**
     * This flag will only be present if
     * include_has_explicit_shared_members is true in
     * list_folder or get_metadata. If this flag is present,
     * it will be true if this file has any explicit shared
     * members. This is different from sharing_info in that
     * this could be true in the case where a file has explicit
     * members but is not contained within a shared folder.
     *
     * @var bool
     */
    protected $has_explicit_shared_members;

    /**
     * Create a new FileMetadata instance
     *
     * @param array $data
     */
    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->id                          = $this->getDataProperty('id');
        $this->tag                         = $this->getDataProperty('.tag');
        $this->rev                         = $this->getDataProperty('rev');
        $this->name                        = $this->getDataProperty('name');
        $this->size                        = $this->getDataProperty('size');
        $this->path_lower                  = $this->getDataProperty('path_lower');
        $this->path_display                = $this->getDataProperty('path_display');
        $this->client_modified             = $this->getDataProperty('client_modified');
        $this->server_modified             = $this->getDataProperty('server_modified');
        $this->has_explicit_shared_members = $this->getDataProperty('has_explicit_shared_members');

        //Make MediaInfo
        $mediaInfo = $this->getDataProperty('media_info');
        if (is_array($mediaInfo)) {
            $this->media_info = new MediaInfo($mediaInfo);
        }

        //Make SharingInfo
        $sharingInfo = $this->getDataProperty('sharing_info');
        if (is_array($sharingInfo)) {
            $this->sharing_info = new FileSharingInfo($sharingInfo);
        }
    }

    /**
     * Get the 'id' property of the file model.
     *
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Get the '.tag' property of the file model.
     *
     * @return string
     */
    public function getTag()
    {
        return $this->tag;
    }

    /**
     * Get the 'name' property of the file model.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Get the 'rev' property of the file model.
     *
     * @return string
     */
    public function getRev()
    {
        return $this->rev;
    }

    /**
     * Get the 'size' property of the file model.
     *
     * @return int
     */
    public function getSize()
    {
        return $this->size;
    }

    /**
     * Get the 'path_lower' property of the file model.
     *
     * @return string
     */
    public function getPathLower()
    {
        return $this->path_lower;
    }

    /**
     * Get the 'media_info' property of the file model.
     *
     * @return \CodeConfig\IDB\Dropbox\Models\MediaInfo
     */
    public function getMediaInfo()
    {
        return $this->media_info;
    }

    /**
     * Get the 'sharing_info' property of the file model.
     *
     * @return \CodeConfig\IDB\Dropbox\Models\FileSharingInfo
     */
    public function getSharingInfo()
    {
        return $this->sharing_info;
    }

    /**
     * Get the 'path_display' property of the file model.
     *
     * @return string
     */
    public function getPathDisplay()
    {
        return $this->path_display;
    }

    /**
     * Get the 'client_modified' property of the file model.
     *
     * @return string
     */
    public function getClientModified()
    {
        return $this->client_modified;
    }

    /**
     * Get the 'server_modified' property of the file model.
     *
     * @return string
     */
    public function getServerModified()
    {
        return $this->server_modified;
    }

    /**
     * Get the 'has_explicit_shared_members' property of the file model.
     *
     * @return bool
     */
    public function hasExplicitSharedMembers()
    {
        return $this->has_explicit_shared_members;
    }
    /**
     * Return an array containing the file data of the file.
     * @param array $extraData Additional data to merge into the file data array.
     *
     * @return array
     */
    public function getFileData($extraData = [])
    {
        $accountId = $extraData['accountId'] ?? 'ccpidb';

        $fileKey = ccpidbGenerateKey($this->id, $accountId);

        $data = [
            'fileId'          => $this->id,
            'fileKey'         => $fileKey,
            'name'            => $this->name,
            'size'            => $this->size,
            'path'            => $this->path_lower,
            'isDir'           => false,
        ];

        /* -------------------------
            Media Info
        -------------------------- */
        $media_info = [];
        if (!empty($this->media_info)) {

            $meta = $this->media_info->getMediaMetadata();

            if ($meta instanceof PhotoMetadata || $meta instanceof VideoMetadata) {

                $media_info = [
                    'location'   => $meta->getLocation(),
                    'dimensions' => $meta->getDimensions(),
                    'timeTaken'  => $meta->getTimeTaken(),
                    'type'       => $meta instanceof PhotoMetadata ? 'photo' : 'video',
                ];

                if ($meta instanceof VideoMetadata) {
                    $media_info['duration'] = $meta->getDuration();
                }
            }
        }

        /* -------------------------
            Path Info
        -------------------------- */
        $pathInfo      = Helpers::getPathinfo($this->path_lower);
        $ext           = strtolower($pathInfo['extension'] ?? '');
        $baseName      = $ext ? str_ireplace('.' . $ext, '', $this->name) : $this->name;

        $canPreviewByCloud = $ext ? MimeTypeManager::isPreviewable($ext) : false;

        if ($ext) {
            $data['extension'] = $ext;
            $data['mimeType']  = MimeTypeManager::getMimeType($ext);
            $data['icon']      = Helpers::defaultIcon($data['mimeType'], '128x128', false);

            if (MimeTypeManager::isThumbnailable($ext)) {
                $data['hasOwnThumbnail'] = true;

                $data['thumbnail'] = ccpidbGetUrl(
                    'thumbnail',
                    $fileKey,
                    $baseName,
                    'lg',
                    $ext
                );
            }
        }

        if (!empty($pathInfo['dirname'])) {
            $data['parent'] = $pathInfo['dirname'];
        }

        /* -------------------------
            Permissions
        -------------------------- */
        $readOnly = $this->sharing_info && $this->sharing_info->isReadOnly();

        $data['permissions'] = [
            'canPreview'  => $canPreviewByCloud,
            'canDownload' => true,
            'canDelete'   => !$readOnly,
            'canAdd'      => !$readOnly,
            'canRename'   => !$readOnly,
            'canMove'     => !$readOnly,
            'canShare'    => true,
        ];

        /* -------------------------
            Additional Data
        -------------------------- */
        $data['additionalData'] = [
            'tag'                      => $this->tag,
            'rev'                      => $this->rev,
            'path_display'             => $this->path_display,
            'clientModified'           => $this->client_modified,
            'serverModified'           => $this->server_modified,
            'hasExplicitSharedMembers' => $this->has_explicit_shared_members,
            'canPreviewByCloud'        => $canPreviewByCloud,
            'basename'                 => $baseName,
            'mediaInfo'                => $media_info,
            'sharingInfo'              => $this->sharing_info,
        ];

        /* -------------------------
            Merge External Data
        -------------------------- */
        return $data + $extraData;
    }
}
