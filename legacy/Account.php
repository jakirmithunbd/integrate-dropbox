<?php

namespace CodeConfig\Legacy;

defined('ABSPATH') or exit('Hey, what are you doing here? You silly human!');

class Account
{
    private $_id;
    private $_name;
    private $_email;
    private $_image;
    private $_type;
    private $_root_namespace_id = '';
    private $_is_verified       = false;
    private $_authorization;

    public function __construct($id, $name, $email, $type = null, $image = null, $root_namespace_id = null)
    {
        $this->_id                = $id;
        $this->_name              = $name;
        $this->_email             = $email;
        $this->_image             = $image;
        $this->_root_namespace_id = $root_namespace_id;
        $this->_type              = $type;
    }

    public function get_id()
    {
        return $this->_id;
    }

    public function get_name()
    {
        return $this->_name;
    }

    public function get_email()
    {
        return $this->_email;
    }

    public function get_image()
    {
        if (empty($this->_image)) {
            return CCPIDB_ASSETS . '/admin/images/dropbox_logo_small.png';
        }

        return $this->_image;
    }

    public function get_type()
    {
        return $this->_type;
    }

    public function get_root_namespace_id()
    {
        return $this->_root_namespace_id;
    }

    public function is_verified()
    {
        return $this->_is_verified;
    }

    public function get_storage_info()
    {
        return;
    }

    public function get_authorization()
    {
        return $this->_authorization;
    }

    public function getData()
    {
        return [
            'id'                 => $this->_id,
            'name'               => [
                                        'given_name'  => $this->_name,
                                        'surname'     => $this->_name,
                                        'display_name'=> $this->_name
                                    ],
            'email'              => $this->_email,
            'image'              => $this->_image,
            'type'               => $this->_type,
            'root_namespace_id'  => $this->_root_namespace_id,
            'is_verified'        => $this->_is_verified
        ];
    }
}

class_alias('CodeConfig\Legacy\Account', 'CodeConfig\IntegrateDropbox\App\Account');
