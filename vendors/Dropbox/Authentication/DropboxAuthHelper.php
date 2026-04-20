<?php

namespace CodeConfig\IDB\Dropbox\Authentication;

use CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException;
use CodeConfig\IDB\Dropbox\Models\AccessToken;

class DropboxAuthHelper
{
    /**
     * The length of CSRF string
     *
     * @const int
     */
    public const CSRF_LENGTH = 32;

    /**
     * OAuth2 Client
     *
     * @var \CodeConfig\IDB\Dropbox\Authentication\OAuth2Client
     */
    protected $oAuth2Client;

    /**
     * Random String Generator
     *
     * @var \CodeConfig\IDB\Dropbox\Security\RandomStringGeneratorInterface
     */
    protected $randomStringGenerator;

    /**
     * Persistent Data Store
     *
     * @var \CodeConfig\IDB\Dropbox\Store\PersistentDataStoreInterface
     */
    protected $persistentDataStore;

    /**
     * Additional User Provided State
     *
     * @var string
     */
    protected $urlState = null;

    /**
     * Create a new DropboxAuthHelper instance
     *
     * @param \CodeConfig\IDB\Dropbox\Authentication\OAuth2Client $oAuth2Client
     * @param \CodeConfig\IDB\Dropbox\Security\RandomStringGeneratorInterface|null $randomStringGenerator
     * @param \CodeConfig\IDB\Dropbox\Store\PersistentDataStoreInterface|null $persistentDataStore
     */
    public function __construct(
        $oAuth2Client,
        $randomStringGenerator = null,
        $persistentDataStore = null
    ) {
        $this->oAuth2Client          = $oAuth2Client;
        $this->randomStringGenerator = $randomStringGenerator;
        $this->persistentDataStore   = $persistentDataStore;
    }

    /**
     * Get OAuth2Client
     *
     * @return \CodeConfig\IDB\Dropbox\Authentication\OAuth2Client
     */
    public function getOAuth2Client()
    {
        return $this->oAuth2Client;
    }

    /**
     * Get the Random String Generator
     *
     * @return \CodeConfig\IDB\Dropbox\Security\RandomStringGeneratorInterface
     */
    public function getRandomStringGenerator()
    {
        return $this->randomStringGenerator;
    }

    /**
     * Get the Persistent Data Store
     *
     * @return \CodeConfig\IDB\Dropbox\Store\PersistentDataStoreInterface
     */
    public function getPersistentDataStore()
    {
        return $this->persistentDataStore;
    }

    /**
     * Get CSRF Token
     *
     * @return string
     */
    protected function getCsrfToken()
    {
        $generator = $this->getRandomStringGenerator();

        return $generator->generateString(static::CSRF_LENGTH);
    }

    /**
     * Get Authorization URL
     *
     * @param string $redirectUri Callback URL to redirect to after authorization
     * @param array $params Additional Params
     * @param string $urlState Additional User Provided State Data
     * @param string $tokenAccessType Either `offline` or `online` or null
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#oauth2-authorize
     *
     * @return string
     */
    public function getAuthUrl($redirectUri = null, array $params = [], $urlState = null, $tokenAccessType = null)
    {
        // If no redirect URI
        // is provided, the
        // CSRF validation
        // is being handled
        // explicitly.
        $state = null;

        // Redirect URI is provided
        // thus, CSRF validation
        // needs to be handled.
        if (! is_null($redirectUri)) {
            //Get CSRF State Token
            $state = $this->getCsrfToken();

            //Set the CSRF State Token in the Persistent Data Store
            $this->getPersistentDataStore()->set('state_' . md5($state), $state);

            //Additional User Provided State Data
            if (! is_null($urlState)) {
                $state .= "|";
                $state .= $urlState;
            }
        }

        //Get OAuth2 Authorization URL
        return $this->getOAuth2Client()->getAuthorizationUrl($redirectUri, $state, $params, $tokenAccessType);
    }

    /**
     * Decode State to get the CSRF Token and the URL State
     *
     * @param string $state State
     *
     * @return array
     */
    protected function decodeState($state)
    {
        $csrfToken = $state;
        $urlState  = null;

        $splitPos = strpos($state, "|");

        if ($splitPos !== false) {
            $csrfToken = substr($state, 0, $splitPos);
            $urlState  = substr($state, $splitPos + 1);
        }

        return ['csrfToken' => $csrfToken, 'urlState' => $urlState];
    }

    /**
     * Validate CSRF Token
     * @param string $csrfToken CSRF Token
     *
     * @throws DropboxClientException
     *
     * @return void
     */
    protected function validateCSRFToken($csrfToken)
    {
        // require_once ABSPATH . 'wp-includes/pluggable.php';

        $tokenInStore = $this->getPersistentDataStore()->get('state_' . md5($csrfToken));

        //Unable to fetch CSRF Token
        if (! $csrfToken || ! $tokenInStore) {
            throw new DropboxClientException("Invalid CSRF Token. Unable to validate CSRF Token.");
        }

        //CSRF Token Mismatch
        // if ( ! wp_verify_nonce( $csrfToken, 'intgd-nonce' ) ) {

        if ($csrfToken !== $tokenInStore) {
            throw new DropboxClientException("Invalid CSRF Token. CSRF Token Mismatch.");
        }

        // return true;

        //Clear the state store
        $this->getPersistentDataStore()->clear('state_' . md5($csrfToken));
    }

    /**
     * Get Access Token
     *
     * @param string $code Authorization Code
     * @param string $state CSRF & URL State
     * @param string $redirectUri Redirect URI used while getAuthUrl
     *
     * @return \CodeConfig\IDB\Dropbox\Models\AccessToken
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     */
    public function getAccessToken($code, $state = null, $redirectUri = null)
    {
        // No state provided
        // Should probably be
        // handled explicitly
        if (! is_null($state)) {
            //Decode the State
            $state = $this->decodeState($state);

            //CSRF Token
            $csrfToken = $state['csrfToken'];

            //Set the URL State
            $this->urlState = $state['urlState'];

            //Validate CSRF Token
            $this->validateCSRFToken($csrfToken);
        }

        //Fetch Access Token
        $accessToken = $this->getOAuth2Client()->getAccessToken($code, $redirectUri);

        //Make and return the model
        return new AccessToken($accessToken);
    }

    /**
     * Get new Access Token by using the refresh token
     *
     * @param \CodeConfig\IDB\Dropbox\Models\AccessToken $accessToken - Current access token object
     * @param string $grantType ['refresh_token']
     */
    public function getRefreshedAccessToken($accessToken, $grantType = 'refresh_token')
    {
        $newToken = $this->getOAuth2Client()->getAccessToken($accessToken->refresh_token, null, $grantType);

        return new AccessToken(
            array_merge(
                $accessToken->getData(),
                $newToken
            )
        );
    }

    /**
     * Revoke Access Token
     *
     * @return void
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     */
    public function revokeAccessToken()
    {
        $this->getOAuth2Client()->revokeAccessToken();
    }

    /**
     * Get URL State
     *
     * @return string
     */
    public function getUrlState()
    {
        return $this->urlState;
    }

    public function refreshToken()
    {
        return $this->getOAuth2Client()->refreshToken();
    }
}
