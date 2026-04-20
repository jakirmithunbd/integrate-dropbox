<?php

namespace CodeConfig\Legacy;

defined('ABSPATH') or exit('Hey, what are you doing here? You silly human!');

class Authorization
{
    private $_isValid;
    private $_accountId;
    private $_accessTokens;
    private $_accessTokensKey = 'integrate_dropbox_access_tokens';

    public function __construct(Account $account)
    {
        $this->_accessTokens = get_option($this->_accessTokensKey, null);
        $this->_accountId    = $account->get_id();
    }

    public function get_access_token($id)
    {
        return $this->_accessTokens[$id] ?? null;
    }

    public function get_account_id()
    {
        return $this->_accountId;
    }

    public function get_is_valid()
    {
        return $this->_isValid;
    }
}

class_alias('CodeConfig\Legacy\Authorization', 'CodeConfig\IntegrateDropbox\App\Authorization');
