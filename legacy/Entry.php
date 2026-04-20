<?php

namespace CodeConfig\Legacy;

defined('ABSPATH') or exit('Hey, what are you doing here? You silly human!');

class Entry
{
    public $id;
    public $rev;
    public $flag;
    public $path;
    public $name;
    public $size;
    public $parent;
    public $basename;
    public $extension;
    public $thumbnail;
    public $thumbnail_size;
    public $last_edited;
    public $description;
    public $shared_links;
    public $save_as = [];
    public $preview_link;
    public $download_link;
    public $children     = [];
    public $mimetype     = '';
    public $is_dir       = false;
    public $trashed      = false;
    public $path_display = '';
    public $thumbnail_dimension;
    public $direct_download_link;
    public $can_preview_by_cloud = false;
    public $can_edit_by_cloud    = false;
    public $permissions          = [
        'canpreview' => false,
        'candelete'  => false,
        'canadd'     => false,
        'canrename'  => false,
        'canmove'    => false,
    ];
    public $thumbnails = [
    ];
    public $has_own_thumbnail = false;
    public $icon              = false;
    public $backup_icon;
    public $media;
    public $additional_data = [];
    // Parent folder, only used for displaying the Previous Folder entry
    public $pf         = false;
    public $has_access = true;

    public function getData()
    {
        return get_object_vars($this);
    }
}

class_alias('CodeConfig\Legacy\Entry', 'CodeConfig\IntegrateDropbox\App\Entry');
