<?php

namespace CodeConfig\Legacy;

use CodeConfig\IDB\Dropbox\Models\BaseModel;

defined('ABSPATH') or exit('Hey, what are you doing here? You silly human!');

class AccessToken extends BaseModel
{
    private $token;
    protected $access_token;
    protected $refresh_token;
    protected $expiry_time;
    protected $token_type;
    protected $bearer;
    protected $uid;
    protected $account_id;
    protected $team_id;
    protected $created;

    public function __construct(array $data)
    {
        parent::__construct($data);

        $this->access_token        = $this->getDataProperty('access_token');
        $this->token_type          = $this->getDataProperty('token_type');
        $this->bearer              = $this->getDataProperty('bearer');
        $this->uid                 = $this->getDataProperty('uid');
        $this->account_id          = $this->getDataProperty('account_id');
        $this->team_id             = $this->getDataProperty('team_id');
        $this->expiry_time         = $this->getDataProperty('expires_in');
        $this->refresh_token       = $this->getDataProperty('refresh_token');
        $this->created             = $this->getDataProperty('created');
    }

    public function getAccessToken(): string
    {
        return $this->token;
    }

    public function getToken(): string
    {
        if (null === $this->access_token) {
            return $this->access_token = $this->token;
        }

        return $this->access_token;
    }

    public function getRefreshToken(): string
    {
        if (null === $this->refresh_token) {
            return $this->refresh_token = $this->getDataProperty('refresh_token');
        }

        return $this->refresh_token;
    }

    public function getExpiryTime(): int
    {
        if (null === $this->expiry_time) {
            return $this->expiry_time = (int) $this->getDataProperty('expires_in');
        }
        return (int) $this->expiry_time;
    }

    public function getTokenType(): string
    {
        if (null === $this->token_type) {
            return $this->token_type = $this->getDataProperty('token_type');
        }
        return $this->token_type;
    }

    public function getBearer(): string
    {
        if (null === $this->bearer) {
            return $this->bearer = $this->getDataProperty('bearer');
        }
        return $this->bearer;
    }

    public function getUid(): string
    {
        if (null === $this->uid) {
            return $this->uid = $this->getDataProperty('uid');
        }
        return $this->uid;
    }

    public function getAccountId(): string
    {
        if (null === $this->account_id) {
            return $this->account_id = $this->getDataProperty('account_id');
        }
        return $this->account_id;
    }

    public function getTeamId(): string
    {
        if (null === $this->team_id) {
            return $this->team_id = $this->getDataProperty('team_id');
        }
        return $this->team_id;
    }

    public function getCreated(): string
    {
        if (null === $this->created) {
            return $this->created = $this->getDataProperty('created');
        }
        return $this->created;
    }

    public function getData()
    {
        $data = parent::getData();
        $data['access_token'] = $this->getAccessToken();
        return $data;
    }
}

class_alias('CodeConfig\Legacy\AccessToken', 'CodeConfig\IntegrateDropbox\SDK\Models\AccessToken');
