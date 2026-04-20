<?php

namespace CodeConfig\IDB\Dropbox\Models;

use CodeConfig\IDB\Utils\Helpers;

use function is_array;

class FolderMetadata extends BaseModel
{
    /**
     * A unique identifier of the folder
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
     * This never contains a slash.
     *
     * @var string
     */
    protected $name;

    /**
     * The lowercased full path in the user's Dropbox.
     * This always starts with a slash.
     *
     * @var string
     */
    protected $path_lower;

    /**
     * Set if this folder is contained in a shared folder.
     *
     * @var \CodeConfig\IDB\Dropbox\Models\FolderSharingInfo
     */
    protected $sharing_info;

    /**
     * The cased path to be used for display purposes only.
     *
     * @var string
     */
    protected $path_display;

    /**
     * Create a new FolderMetadata instance
     *
     * @param array $data
     */
    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->id           = $this->getDataProperty('id');
        $this->tag          = $this->getDataProperty('.tag');
        $this->name         = $this->getDataProperty('name');
        $this->path_lower   = $this->getDataProperty('path_lower');
        $this->path_display = $this->getDataProperty('path_display');

        //Make SharingInfo
        $sharingInfo = $this->getDataProperty('sharing_info');
        if (is_array($sharingInfo)) {
            $this->sharing_info = new FolderSharingInfo($sharingInfo);
        }
    }

    /**
     * Get the 'id' property of the folder model.
     *
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Get the '.tag' property of the folder model.
     *
     * @return string
     */
    public function getTag()
    {
        return $this->tag;
    }

    /**
     * Get the 'name' property of the folder model.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Get the 'path_lower' property of the folder model.
     *
     * @return string
     */
    public function getPathLower()
    {
        return $this->path_lower;
    }

    /**
     * Get the 'sharing_info' property of the folder model.
     *
     * @return \CodeConfig\IDB\Dropbox\Models\FolderSharingInfo
     */
    public function getSharingInfo()
    {
        return $this->sharing_info;
    }

    /**
     * Get the 'path_display' property of the folder model.
     *
     * @return string
     */
    public function getPathDisplay()
    {
        return $this->path_display;
    }

    /**
     * Return an array containing the file data of the folder.
     * @param array $extraData Additional data to merge into the file data array.
     *
     * @return array
     */
    public function getFileData($extraData = [])
    {
        $accountId = $extraData['accountId'] ?? 'ccpidb';

        $fileKey = ccpidbGenerateKey($this->getId(), $accountId);

        $data = [
            'fileId'            => $this->getId(),
            'fileKey'           => $fileKey,
            'name'              => $this->getName(),
            'size'              => 0,
            'path'              => $this->getPathLower(),
            'isDir'             => true,

            // Folder defaults
            'extension'         => 'folder',
            'mimeType'          => 'folder',
            'icon'              => Helpers::defaultIcon('folder', '128x128', true),
            'hasOwnThumbnail'   => false,
        ];

        /* -----------------------------------
        Additional metadata
        ----------------------------------- */
        $data['additionalData'] = [
            'tag'                      => $this->tag,
            'rev'                      => 0,
            'path_display'             => $this->path_display,
            'clientModified'           => 0,
            'serverModified'           => 0,
            'hasExplicitSharedMembers' => false,
            'canPreviewByCloud'        => false,
            'basename'                 => $this->getName(),
            'mediaInfo'                => null,
            'sharingInfo'              => $this->getSharingInfo(),
        ];

        /* -----------------------------------
            Parent folder info
        ----------------------------------- */
        $pathInfo = Helpers::getPathinfo($this->path_lower);

        if (!empty($pathInfo['dirname'])) {
            $data['parent']  = $pathInfo['dirname'];
            $data['dirname'] = $pathInfo['dirname'];
        }

        /* -----------------------------------
            Permissions (optimized)
        ----------------------------------- */
        $readOnly = $this->sharing_info && $this->sharing_info->isReadOnly();

        $data['permissions'] = [
            'canPreview'  => false,
            'canDownload' => true,
            'canDelete'   => !$readOnly,
            'canAdd'      => !$readOnly,
            'canRename'   => !$readOnly,
            'canMove'     => !$readOnly,
            'canShare'    => true,
        ];

        /* -----------------------------------
            Merge external data
        ----------------------------------- */
        return $data + $extraData;
    }
}
