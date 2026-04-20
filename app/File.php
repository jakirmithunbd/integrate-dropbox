<?php

namespace CodeConfig\IDB\App;

use function array_key_exists;

use CodeConfig\IDB\Dropbox\Models\BaseModel;
use CodeConfig\IDB\Models\Files;
use CodeConfig\IDB\Utils\Helpers;

use function is_array;
use function is_string;

defined('ABSPATH') or exit('Hey, what are you doing here? You silly human!');


class File extends BaseModel
{
    private $fileId;
    private $fileKey;
    private $path;
    private $name;
    private $size;
    private $parent;
    private $accountId;
    private $mimeType;
    private $extension;
    private $thumbnail;
    private $thumbnailRatio;
    private $description;
    private $sharedLink;
    private $isDir                = false;
    private $permissions          = [];
    private $hasOwnThumbnail      = false;
    private $icon                 = false;
    private $additionalData       = [];
    private $metaData             = [];

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->fileId             = $this->getDataProperty('fileId', '');
        $this->fileKey            = $this->getDataProperty('fileKey', '');
        $this->path               = $this->getDataProperty('path', '');
        $this->name               = $this->getDataProperty('name', '');
        $this->size               = $this->getDataProperty('size', 0);
        $this->parent             = $this->getDataProperty('parent', '');
        $this->accountId          = $this->getDataProperty('accountId', '');
        $this->mimeType           = $this->getDataProperty('mimeType', '');
        $this->extension          = $this->getDataProperty('extension', '');
        $this->thumbnail          = $this->getDataProperty('thumbnail', '');
        $this->thumbnailRatio     = $this->getDataProperty('thumbnailRatio', '');
        $this->description        = $this->getDataProperty('description', '');
        $this->sharedLink         = $this->getDataProperty('sharedLink', '');
        $this->isDir              = $this->getDataProperty('isDir', false);
        $this->permissions        = $this->getDataProperty('permissions', []);
        $this->hasOwnThumbnail    = $this->getDataProperty('hasOwnThumbnail', false);
        $this->icon               = $this->getDataProperty('icon', false);
        $this->additionalData     = $this->getDataProperty('additionalData', []);
        $this->metaData           = $this->getDataProperty('metaData', []);

        if ($this->getDataProperty('id') === null || $this->getDataProperty('update') === true) {
            Files::getInstance($this->getDataProperty('accountId'))->addFile($this->getData());
        }
    }

    public function getId()
    {
        return $this->fileId;
    }

    public function getFileKey()
    {
        return $this->fileKey;
    }

    public function getPath()
    {
        return $this->path;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getParent()
    {
        return $this->parent;
    }
    public function getAccountId()
    {
        return $this->accountId;
    }

    public function getSize()
    {
        return $this->size;
    }

    public function getExtension()
    {
        return $this->extension;
    }

    public function getThumbnail($size = 'lg')
    {
        $availableSizes = ccpidbGetAvailableThumbnailSizes();

        $sizeName = array_key_exists($size, $availableSizes) ? $size : 'lg';

        if (isset($availableSizes[$sizeName]) && ! empty($this->thumbnail) && $this->hasOwnThumbnail) {
            return str_replace('lg', $sizeName, $this->thumbnail);
        }

        return $this->thumbnail;
    }

    public function getThumbnailRatio()
    {
        return $this->thumbnailRatio;
    }

    public function getDescription()
    {
        return $this->description;
    }

    public function getSharedLink()
    {
        return $this->sharedLink;
    }

    public function getPreviewLink()
    {
        return Helpers::shareLinkToPreviewLink($this->sharedLink);
    }

    public function getShareLink()
    {
        return $this->sharedLink;
    }

    public function getDownloadLink()
    {
        return Helpers::shareLinkToDownloadLink($this->sharedLink);
    }

    public function getMimetype()
    {
        return $this->mimeType;
    }

    public function isDir()
    {
        return $this->isDir;
    }

    public function getPermissions()
    {
        return $this->permissions;
    }

    public function hasOwnThumbnail()
    {
        return $this->hasOwnThumbnail;
    }

    public function getIcon($size = 'default')
    {
        $sizes = [
            'default' => '64x64',
            'small'   => '32x32',
            'large'   => '128x128',
            'extra'   => '256x256',
        ];

        $size = $sizes[$size] ?? '64x64';
        if (empty($this->icon)) {
            return Helpers::defaultIcon($this->mimeType, $size, $this->isDir);
        }

        return str_replace('32x32', '128x128', $this->icon);
    }

    public function getAdditionalData($key = null, $default = null)
    {
        $additionalData = $this->additionalData;

        if (empty($additionalData)) {
            $additionalData = $this->getDataProperty('additionalData', []);

            if (empty($additionalData)) {
                return $default;
            }
        }

        if (empty($key) || !is_string($key)) {
            return $this->additionalData;
        }

        if (strpos($key, '.') !== false) {
            $keys  = explode('.', $key);
            $value = $additionalData;

            foreach ($keys as $innerKey) {
                if (!is_array($value) || !array_key_exists($innerKey, $value)) {
                    return $default;
                }
                $value = $value[$innerKey];
            }

            return $value;
        } elseif (array_key_exists($key, $additionalData)) {
            return $additionalData[$key];
        }

        return $this->additionalData;
    }

    public function getMetaData($key = null)
    {
        if (empty($this->metaData)) {
            $this->metaData = $this->getDataProperty('metaData', []);
        }

        if (null !== $key) {
            return $this->metaData[$key] ?? null;
        }

        return $this->metaData;
    }
}
