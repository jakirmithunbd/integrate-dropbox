<?php

namespace CodeConfig\IDB\Dropbox\Authentication;

use CodeConfig\IDB\Dropbox\DropboxApp;
use CodeConfig\IDB\Dropbox\DropboxClient;
use CodeConfig\IDB\Dropbox\DropboxRequest;

class OAuth2Client
{
    /**
     * The Base URL
     *
     * @const string
     */
    public const BASE_URL = "https://dropbox.com";

    /**
     * Auth Token URL
     *
     * @const string
     */
    public const AUTH_TOKEN_URL = "https://api.dropboxapi.com/oauth2/token";

    /**
     * The Dropbox App
     *
     * @var \CodeConfig\IDB\Dropbox\DropboxApp
     */
    protected $app;

    /**
     * The Dropbox Client
     *
     * @var \CodeConfig\IDB\Dropbox\DropboxClient
     */
    protected $client;

    /**
     * Random String Generator
     *
     * @var \CodeConfig\IDB\Dropbox\Security\RandomStringGeneratorInterface
     */
    protected $randStrGenerator;

    /**
     * Create a new DropboxApp instance
     *
     * @param \CodeConfig\IDB\Dropbox\DropboxApp $app
     * @param \CodeConfig\IDB\Dropbox\DropboxClient $client
     * @param \CodeConfig\IDB\Dropbox\Security\RandomStringGeneratorInterface|null $randStrGenerator
     */
    public function __construct($app, $client, $randStrGenerator = null)
    {
        $this->app              = $app;
        $this->client           = $client;
        $this->randStrGenerator = $randStrGenerator;
    }

    /**
     * Build URL
     *
     * @param string $endpoint
     * @param array $params Query Params
     *
     * @return string
     */
    protected function buildUrl($endpoint = '', array $params = [])
    {
        $queryParams = http_build_query($params, '', '&');

        return static::BASE_URL . $endpoint . '?' . $queryParams;
    }

    /**
     * Get the Dropbox App
     *
     * @return \CodeConfig\IDB\Dropbox\DropboxApp
     */
    public function getApp()
    {
        return $this->app;
    }

    /**
     * Get the Dropbox Client
     *
     * @return \CodeConfig\IDB\Dropbox\DropboxClient
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Get the OAuth2 Authorization URL
     *
     * @param string $redirectUri Callback URL to redirect user after authorization.
     *                            If null is passed, redirect_uri will be omitted
     *                            from the url and the code will be presented directly
     *                            to the user.
     * @param string $state CSRF Token
     * @param array $params Additional Params
     * @param string $tokenAccessType Either `offline` or `online` or null
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#oauth2-authorize
     *
     * @return string
     */
    public function getAuthorizationUrl($redirectUri = null, $state = null, array $params = [], $tokenAccessType = null)
    {
        //Request Parameters
        $params = array_merge([
            'client_id'         => $this->getApp()->getClientId(),
            'response_type'     => 'code',
            'state'             => $state,
            'token_access_type' => $tokenAccessType,
        ], $params);

        if ($redirectUri !== null) {
            $params['redirect_uri'] = $redirectUri;
        }

        return $this->buildUrl('/oauth2/authorize', $params);
    }

    /**
     * Get Access Token
     *
     * @param string $code Authorization Code | Refresh Token
     * @param string $redirectUri Redirect URI used while getAuthorizationUrl
     * @param string $grant_type Grant Type ['authorization_code' | 'refresh_token']
     *
     * @return array
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     */
    public function getAccessToken($code, $redirectUri = null, $grant_type = 'authorization_code')
    {
        //Request Params
        $params = [
            'code'          => $code,
            'grant_type'    => $grant_type,
            'client_id'     => $this->getApp()->getClientId(),
            'client_secret' => $this->getApp()->getClientSecret(),
            'redirect_uri'  => $redirectUri,
        ];

        // if ( $grant_type === 'refresh_token' ) {
        //     $params = [
        //         'refresh_token' => $code,
        //         'grant_type'    => $grant_type,
        //         'client_id'     => $this->getApp()->getClientId(),
        //         'client_secret' => $this->getApp()->getClientSecret(),
        //     ];
        // }

        $params = http_build_query($params, '', '&');

        $apiUrl = static::AUTH_TOKEN_URL;
        $uri    = $apiUrl . "?" . $params;

        //Send Request through the DropboxClient
        //Fetch the Response (DropboxRawResponse)
        $response = $this->getClient()
            ->getHttpClient()
            ->send($uri, "POST", null);

        //Fetch Response Body
        $body = $response->getBody();

        $decoded_body = json_decode((string) $body, true);

        $decoded_body['created'] ??= time();

        //Decode the Response body to associative array
        //and return
        return $decoded_body;
    }

    /**
     *  Refresh access Token
     *
     * @return \CodeConfig\IDB\Dropbox\Models\AccessToken
     */
    public function refreshToken()
    {
        $accessToken = $this->getApp()->getAccessToken();

        //Request Params
        $params = [
            'refresh_token' => $accessToken->getRefreshToken(),
            'grant_type'    => 'refresh_token',
            'client_id'     => $this->getApp()->getClientId(),
            'client_secret' => $this->getApp()->getClientSecret(),
        ];

        $params = http_build_query($params);

        $apiUrl = static::AUTH_TOKEN_URL;
        $uri    = "$apiUrl?$params";

        $response = $this->getClient()
            ->getHttpClient()
            ->send($uri, "POST", null);

        $body = $response->getBody();

        $token = json_decode((string) $body, true);

        $accessToken->setToken($token['access_token']);
        $accessToken->setExpiryTime($token['expires_in']);
        $accessToken->setCreated(time());

        if (!empty($token['refresh_token'])) {
            $accessToken->setRefreshToken($token['refresh_token']);
        }

        return $accessToken;
    }

    /**
     * Returns if the access_token is expired.
     *
     * @return bool returns True if the access_token is expired
     */
    public function isAccessTokenExpired()
    {
        $accessToken = $this->getApp()->getAccessToken();

        if (empty($accessToken)) {
            return false;
        }

        if ($accessToken->getExpiryTime() < 0) {
            return false;
        }

        // If the token is set to expire in the next 120 seconds.
        return ($accessToken->getCreated()
            + ($accessToken->getExpiryTime() - 120)) < time();
    }

    /**
     * Disables the access token
     *
     * @return void
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     */
    public function revokeAccessToken()
    {
        //Access Token
        $accessToken = $this->getApp()->getAccessToken();

        //Request
        $request = new DropboxRequest("POST", "/auth/token/revoke", $accessToken);
        // Do not validate the response
        // since the /token/revoke endpoint
        // doesn't return anything in the response.
        // See: https://www.dropbox.com/developers/documentation/http/documentation#auth-token-revoke
        $request->setParams(['validateResponse' => false]);

        //Revoke Access Token
        $this->getClient()->sendRequest($request);
    }
}
