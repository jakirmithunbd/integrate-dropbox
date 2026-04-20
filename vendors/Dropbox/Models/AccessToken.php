<?php

namespace CodeConfig\IDB\Dropbox\Models;

use CodeConfig\IDB\Utils\Helpers;

class AccessToken extends BaseModel
{
    /**
     * Access Token
     *
     * @var string
     */
    protected $access_token;

    /**
     * Refresh Token
     *
     * @var string
     */
    protected $refresh_token;

    /**
     * Expiry Time for the token
     *
     * @var string
     */
    protected $expiry_time;

    /**
     * Token Type
     *
     * @var string
     */
    protected $token_type;

    /**
     * Bearer
     *
     * @var string
     */
    protected $bearer;

    /**
     * User ID
     *
     * @var string
     */
    protected $uid;

    /**
     * Account ID
     *
     * @var string
     */
    protected $account_id;

    /**
     * Team ID
     *
     * @var string
     */
    protected $team_id;

    /**
     * Created.
     *
     * @var string
     */
    protected $created;

    /**
     * Create a new AccessToken instance
     *
     * @param array $data
     */
    public function __construct(array $data)
    {
        $accessToken  = $data['access_token']  ?? '';
        $refreshToken = $data['refresh_token'] ?? '';
        unset($data['access_token'], $data['refresh_token']);
        $data['access_token']  = Helpers::encode($accessToken);
        $data['refresh_token'] = Helpers::encode($refreshToken);
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

    /**
     * Get Access Token
     *
     * @return string
     */
    public function getToken()
    {
        return Helpers::decode($this->access_token);
    }

    /**
     * Set Access Token
     *
     * @param string $token
     * @return AccessToken
     */
    public function setToken($token)
    {
        $this->access_token = Helpers::encode($token ?? '');

        return $this;
    }

    /**
     * Get Refresh Token
     *
     * @return string
     */
    public function getRefreshToken()
    {
        return Helpers::decode($this->refresh_token);
    }

    /**
     * Set Refresh Token
     *
     * @param string $refresh_token
     * @return AccessToken
     */
    public function setRefreshToken($refresh_token)
    {
        $this->refresh_token = Helpers::encode($refresh_token ?? '');

        return $this;
    }

    /**
     * Get the expiry time
     *
     * @return int
     */
    public function getExpiryTime()
    {
        return (int) $this->expiry_time;
    }

    /**
     * Set the expiry time
     *
     * @param int $expiry_time
     * @return AccessToken
     */
    public function setExpiryTime($expiry_time)
    {
        $this->expiry_time = $expiry_time;

        return $this;
    }

    /**
     * Get Token Type
     *
     * @return string
     */
    public function getTokenType()
    {
        return $this->token_type;
    }

    /**
     * Set Token Type
     *
     * @param string $token_type
     * @return AccessToken
     */
    public function setTokenType($token_type)
    {
        $this->token_type = $token_type;

        return $this;
    }

    /**
     * Get Bearer
     *
     * @return string
     */
    public function getBearer()
    {
        return $this->bearer;
    }

    /**
     * Get User ID
     *
     * @return string
     */
    public function getUid()
    {
        return $this->uid;
    }

    /**
     * Get Account ID
     *
     * @return string
     */
    public function getAccountId()
    {
        return $this->account_id;
    }

    /**
     * Get Team ID
     *
     * @return string
     */
    public function getTeamId()
    {
        return $this->team_id;
    }

    /**
     * Set created timestamp
     *
     * @param string $created
     * @return self
     */
    public function setCreated(string $created)
    {
        $this->created = $created;

        return $this;
    }

    /**
     * Get created timestamp
     *
     * @return string
     */
    public function getCreated()
    {
        return $this->created;
    }
}
