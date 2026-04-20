<?php

namespace CodeConfig\IDB\Dropbox;

use CodeConfig\IDB\Dropbox\Models\AccessToken;

class DropboxApp
{
    /**
     * The Client ID of the App
     *
     * @link https://www.dropbox.com/developers/apps
     *
     * @var string
     */
    protected $clientId;

    /**
     * The Client Secret of the App
     *
     * @link https://www.dropbox.com/developers/apps
     *
     * @var string
     */
    protected $clientSecret;

    /**
     * The Access Token
     *
     * @var \CodeConfig\IDB\Dropbox\Models\AccessToken
     */
    protected $accessToken;

    /**
     * Create a new Dropbox instance
     *
     * @param string $clientId Application Client ID
     * @param string $clientSecret Application Client Secret
     * @param AccessToken|null $accessToken Access Token
     */
    public function __construct($clientId, $clientSecret, $accessToken = null)
    {
        $this->clientId     = $clientId;
        $this->clientSecret = $clientSecret;

        $accessTokenData    = $accessToken ? $accessToken->getData() : [];
        $this->accessToken  = new AccessToken($accessTokenData);
    }

    /**
     * Get the App Client ID
     *
     * @return string
     */
    public function getClientId()
    {
        return $this->clientId;
    }

    /**
     * Get the App Client Secret
     *
     * @return string
     */
    public function getClientSecret()
    {
        return $this->clientSecret;
    }

    /**
     * Get the Access Token
     *
     * @return \CodeConfig\IDB\Dropbox\Models\AccessToken
     */
    public function getAccessToken()
    {
        return $this->accessToken;
    }

    /**
     * Set the Access Token
     *
     * @return \CodeConfig\IDB\Dropbox\DropboxApp
     */
    public function setAccessToken($accessToken)
    {
        $this->accessToken = $accessToken;

        return $this;
    }
}
