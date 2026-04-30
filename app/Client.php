<?php

namespace CodeConfig\IDB\App;

use CodeConfig\IDB\Dropbox\Dropbox;
use CodeConfig\IDB\Dropbox\DropboxApp;
use CodeConfig\IDB\Dropbox\Models\AccessToken;
use CodeConfig\IDB\Dropbox\Store\DatabasePersistentDataStore;
use CodeConfig\IDB\Models\Notices;
use CodeConfig\IDB\Utils\Helpers;
use CodeConfig\IDB\Utils\Singleton;
use Exception;
use WP_Error;

defined('ABSPATH') || exit('No direct script access allowed');


class Client
{
    use Singleton;

    /**
     * Dropbox Client instance
     *
     * @var Dropbox
     */
    public $client;

    /**
     * Account ID associated with the client
     *
     * @var int
     */
    public $accountId;

    /**
     * Account details
     *
     * @var Account
     */
    public $account;

    /**
     * Dropbox App Key
     *
     * @var string
     */
    private $appKey;

    /**
     * Dropbox App Secret
     *
     * @var string
     */
    private $appSecret;

    /**
     * Redirect URI for OAuth
     *
     * @var string
     */
    private $redirectUri;


    /**
     * Constructor to initialize the client.
     *
     * @param string|null $accountId Optional account ID to configure the client for.
     */
    public function __construct($accountId = null)
    {
        $this->setCredentials();

        $this->accountId = $accountId;
        if ('new' === $accountId) {
            return;
        }

        $account = Accounts::getInstance()->getAccount($this->accountId);

        if ($account instanceof Account) {
            $this->account    = $account;
            $this->accountId  = $account->getId();
            if ($account->getLost()) {

                return;
            }
        }
    }

    /**
     * Get the Google Client instance.
     *
     * This method returns the Google Client instance for the current account. If the client instance does not exist yet,
     * it will be created.
     *
     * @return Dropbox|WP_Error The Google Client instance or WP_Error on failure.
     */
    public function getClient()
    {
        if (empty($this->client)) {
            $client = $this->gettingClient();

            if (empty($client)) {
                return new WP_Error(403, __('Could not initialize Dropbox client.', 'integrate-dropbox'));
            }

            if (is_wp_error($client)) {
                return $client;
            }

            $this->client = $client;
        }

        return $this->client;
    }

    public function getNewClient($accessToken = null, $appKey = null, $appSecret = null)
    {
        if ($appKey !== null) {
            $this->appKey = $appKey;
        }

        if ($appSecret !== null) {
            $this->appSecret = $appSecret;
        }

        $this->initializeDropboxClient($accessToken);

        if (!empty($accessToken)) {
            return $this->refreshToken();
        }

        return $this->client;
    }

    /**
     * Get the authorization URL.
     * @param array $prompt Optional prompt parameters for the authorization URL default ['prompt' => 'login'].
     * @return string|WP_Error The authorization URL.
     */
    public function getAuthUrl($prompt = ['prompt' => 'login'])
    {

        $authHelper      = $this->getNewClient()->getAuthHelper();

        $tokenAccessType = 'offline';
        $nonce           = wp_create_nonce('ccpidb-auth-nonce');

        return $authHelper ? $authHelper->getAuthUrl($this->redirectUri, $prompt, $nonce, $tokenAccessType) : '';
    }

    /**
     * Retrieve the access token for the current account.
     *
     * @return string The access token associated with the account, or false if no token is found.
     */
    public function getAccessToken()
    {
        $accessToken = $this->getClient()->getAccessToken();

        if ($accessToken instanceof AccessToken) {
            return $accessToken->getToken();
        }

        return false;
    }

    /**
     * Retrieve the refresh token for the current account.
     *
     * This function will return the refresh token associated with the account.
     *
     * @return string The refresh token associated with the account, or false if no token is found.
     */
    public function getRefreshToken()
    {
        $accessToken = $this->getClient()->getAccessToken();

        if ($accessToken instanceof AccessToken) {
            return $accessToken->getRefreshToken();
        }

        return false;
    }

    /**
     * Refresh the access token
     *
     * This function will refresh the access token and will update the account
     * with the new access token. If the refresh token is empty, it will revoke
     * the token and delete the account.
     *
     * @return Dropbox|WP_Error
     */
    public function refreshToken()
    {
        $accessToken  = $this->getClient()->getAccessToken();
        $refreshToken = $accessToken ? $accessToken->getRefreshToken() : null;

        if (empty($refreshToken)) {
            // Revoke the token # TODO -> Remove token not delete account
            $this->getClient()->getAuthHelper()->revokeAccessToken();
            $lostAccount = Accounts::getInstance()->lostAccount($this->accountId);

            if (is_wp_error($lostAccount)) {
                // Notices::getInstance()->add([
                //     'type'        => 'error',
                //     'title'       => __('Account connection lost', 'integrate-dropbox'),
                //     'description' => $lostAccount->get_error_message(),
                // ]);

                return $lostAccount;
            }
            // Notices::getInstance()->add([
            //     'type'        => 'error',
            //     'title'       => __('Account connection lost', 'integrate-dropbox'),
            //     'description' => __('Your account connection was lost. Please re-authorize to continue.', 'integrate-dropbox'),
            // ]);
        }

        try {
            $newAccessToken = $this->client->getAuthHelper()->refreshToken();
            $this->getClient()->setAccessToken($newAccessToken);

            if (empty($this->account)) {
                return $this->client;
            }

            $this->account->setAccessTokens($newAccessToken->getData());

            return $this->client;
        } catch (\Throwable $th) {
            $accounts = Accounts::getInstance();

            $syncAccount = $accounts->syncAccount($this->accountId);
            if (is_wp_error($syncAccount) || empty($syncAccount)) {
                $lostAccount = $accounts->lostAccount($this->accountId);

                if (is_wp_error($lostAccount)) {
                    return $lostAccount;
                }
            }

            if ($syncAccount instanceof Account) {
                $this->account = $syncAccount;

                return $this->gettingClient();
            }


            return new WP_Error(403, $th->getMessage());
        }
    }

    // =============== PRIVATE METHODS ===============

    /**
     * Setup the Google Client
     *
     * This function sets up the Google Client and check if the authorization is
     * still valid. If the authorization is not valid, it will refresh the token.
     *
     * @return Dropbox|WP_Error
     */
    private function gettingClient()
    {
        $accessToken = $this->account ? $this->account->getAccessToken() : null;
        try {
            $this->initializeDropboxClient($accessToken);
        } catch (Exception $exception) {
            return new WP_Error(403, $exception->getMessage());
        }

        if (empty($this->account) || empty($this->account->hasAccessToken())) {
            return $this->client;
        }

        $accessToken = $this->account->getAccessToken();

        if (empty($accessToken)) {

            return new WP_Error(403, __('Access token is empty. Please re-authenticate.', 'integrate-dropbox'));
        }

        $this->client->setAccessToken($accessToken);

        // if (!$this->client->isAccessTokenExpired()) {
        //     return $this->client;
        // }

        try {
            $refreshedClient = $this->refreshToken();

            if (!$refreshedClient) {
                return new WP_Error(403, __('Refresh token is empty. Please re-authenticate.', 'integrate-dropbox'));
            }

            if (is_wp_error($refreshedClient)) {
                return new WP_Error(403, 'Error refreshing token: ' . $refreshedClient->get_error_message());
            }

            return $refreshedClient;
        } catch (Exception $exception) {

            return new WP_Error(403, $exception->getMessage());
        }
    }

    /**
     * Summary of initializeDropboxClient
     * @param AccessToken|null $accessToken
     * @return void
     */
    private function initializeDropboxClient($accessToken = null)
    {
        $this->client = new Dropbox($this->configureDropboxApp($accessToken), ['persistent_data_store' => new DatabasePersistentDataStore()]);
    }

    /**
     * Summary of configureDropboxApp
     * @param AccessToken|null $accessToken
     * @return DropboxApp
     */
    private function configureDropboxApp($accessToken = null)
    {
        $accessToken = $accessToken ?: null;

        return new DropboxApp(
            $this->appKey,
            $this->appSecret,
            $accessToken
        );
    }

    private function setCredentials()
    {
        $clientId           = Helpers::getSetting('accounts.appKey', false);
        $clientSecret       = Helpers::getSetting('accounts.appSecret', false);
        $redirectUri        = Helpers::redirectUrl();

        if (empty($clientId)) {
            Notices::getInstance()->add([
                'type'        => 'error',
                'title'       => __('Dropbox App Key Missing', 'integrate-dropbox'),
                'description' => __('Please set the Dropbox App Key in the plugin settings to enable Dropbox integration.', 'integrate-dropbox'),
            ]);

            return;
        }

        if (empty($clientSecret)) {
            Notices::getInstance()->add([
                'type'        => 'error',
                'title'       => __('Dropbox App Secret Missing', 'integrate-dropbox'),
                'description' => __('Please set the Dropbox App Secret in the plugin settings to enable Dropbox integration.', 'integrate-dropbox'),
            ]);

            return;
        }

        if (empty($redirectUri)) {
            Notices::getInstance()->add([
                'type'        => 'error',
                'title'       => __('Dropbox App Redirect URI Missing', 'integrate-dropbox'),
                'description' => __('Please set the Dropbox App Redirect URI in the plugin settings to enable Dropbox integration.', 'integrate-dropbox'),
            ]);

            return;
        }

        $this->appKey      = apply_filters('ccpidb/clientId', $clientId);
        $this->appSecret   = apply_filters('ccpidb/clientSecret', $clientSecret);
        $this->redirectUri = apply_filters('ccpidb/redirectUri', $redirectUri);
    }
}
