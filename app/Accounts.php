<?php

namespace CodeConfig\IDB\App;

use CodeConfig\IDB\Models\Account as AccountModel;
use CodeConfig\IDB\Models\Files;
use CodeConfig\IDB\Utils\Singleton;
use Exception;
use function is_bool;
use WP_Error;
defined( 'ABSPATH' ) || exit( 'No direct script access allowed' );
class Accounts {
    use Singleton;
    /**
     * Instance of the AccountModel class, used to interact with the database
     *
     * @var AccountModel
     */
    private $model;

    /**
     * Array of Account objects, used to store the accounts in memory
     *
     * @var Account[]|WP_Error
     */
    private $accounts;

    /**
     * Instance of the Account class, used to store the current account
     *
     * @var Account|WP_Error
     */
    private $currentAccount;

    public function __construct() {
        $this->model = new AccountModel();
        $this->accounts = $this->model->getAccounts();
        $this->currentAccount = $this->model->getAccount();
    }

    /**
     * Add a new account to the database
     *
     * @param Account $account The account object to add
     *
     * @return Account|WP_Error The newly created account
     */
    public function addAccount( $account ) {
        $result = $this->model->addAccount( $account );
        if ( !is_wp_error( $result ) ) {
            $this->accounts[$account->getId()] = $account;
        }
        return $account;
    }

    /**
     * Retrieves all accounts from memory or the database.
     *
     * If the accounts are not already loaded in memory, it fetches them
     * from the database and stores them in memory.
     * @param string $accountFormat The format in which to return the accounts (OBJECT or ARRAY_A, ARRAY_N).
     *
     * @return array|WP_Error An array of Account objects, or a WP_Error object if an error occurred.
     */
    public function getAccounts( $accountFormat = OBJECT, $excludes = [] ) {
        if ( !isset( $this->accounts ) || empty( $this->accounts ) ) {
            $this->accounts = $this->model->getAccounts();
        }
        if ( is_wp_error( $this->accounts ) ) {
            return [];
        }
        if ( !empty( $this->accounts ) ) {
            if ( $accountFormat === ARRAY_N ) {
                return array_map( fn( $account ) => $account->toArrayData( $excludes ), array_values( $this->accounts ) );
            } elseif ( $accountFormat === ARRAY_A ) {
                return array_map( fn( $account ) => $account->toArrayData( $excludes ), $this->accounts );
            }
        }
        return $this->accounts;
    }

    /**
     * {@inheritdoc}
     *
     * The `accounts` property is a cached version of all accounts in the database.
     * It is stored in the session so that it does not need to be fetched from the
     * database on every page load.
     *
     * The `currentAccount` property is the currently active account, as determined
     * by the `switchAccount` method.
     */
    public function __sleep() {
        return ['accounts', 'currentAccount'];
    }

    /**
     * On wakeup, fetch all accounts from the database and store them in memory.
     *
     * This method is called automatically when the object is unserialized.
     *
     * @return void
     */
    public function __wakeup() {
        $this->accounts = $this->model->getAccounts();
    }

    /**
     * Retrieves an account by ID.
     *
     * If no account ID is provided, the currently active account is returned.
     *
     * @param string|null $accountId The ID of the account to retrieve.
     *
     * @return Account|WP_Error The account object if found, or a WP_Error object if not found.
     */
    public function getAccount( $accountId = null ) {
        if ( empty( $accountId ) ) {
            return $this->currentAccount;
        }
        if ( !isset( $this->accounts[$accountId] ) ) {
            $account = $this->model->getAccount( $accountId );
            if ( is_wp_error( $account ) ) {
                return $account;
            }
            $this->accounts[$accountId] = $account;
        }
        return $this->accounts[$accountId];
    }

    /**
     * Syncs an account from Dropbox with the local database.
     *
     * Retrieves the account information from Dropbox and updates the local
     * database with the retrieved information. If the account does not exist in
     * the database, it is created.
     *
     * @param string $accountId The ID of the account to sync.
     *
     * @return Account|WP_Error The updated account object if the synchronization
     *                          was successful, or a WP_Error object if an error
     *                          occurred.
     */
    public function syncAccount( $accountId ) {
        try {
            $dropboxClient = Client::getInstance( $accountId )->getClient();
            if ( is_wp_error( $dropboxClient ) ) {
                return $dropboxClient;
            }
            $account = $dropboxClient->getCurrentAccount();
            $storage = $dropboxClient->getSpaceUsage();
            $accessToken = $dropboxClient->getAccessToken();
            $accountData = [
                'id'            => $account->getAccountId(),
                'accountKey'    => $account->getAccountKey(),
                'name'          => $account->getNameDetails(),
                'email'         => $account->getEmail(),
                'photo'         => $account->getProfilePhotoUrl(),
                'rootInfo'      => $account->getRootInfo(),
                'storage'       => $storage,
                'country'       => $account->getCountry(),
                'locale'        => $account->getLocale(),
                'type'          => $account->getAccountType(),
                'referralLink'  => $account->getReferralLink(),
                'tokens'        => $accessToken->getData(),
                'active'        => true,
                'emailVerified' => $account->emailIsVerified(),
                'disabled'      => $account->isDisabled(),
                'isPaired'      => $account->isPaired(),
            ];
            $updatedAccount = new Account($accountData);
            return $this->updateAccount( $updatedAccount );
        } catch ( Exception $e ) {
            return new WP_Error(400, 'Failed to sync account: ' . $e->getMessage());
        }
    }

    /**
     * Retrieve an account by its key.
     *
     * @param string $key The key of the account to retrieve.
     * @return Account|WP_Error The account object if found, or a WP_Error object if not found.
     */
    public function getAccountByKey( $key ) {
        foreach ( $this->accounts as $account ) {
            if ( $account->getKey() === $key && !$account->getLost() ) {
                return $account;
            }
        }
        return $this->model->getAccountByKey( $key );
    }

    /**
     * Updates an existing account in the database.
     *
     * @return Account|WP_Error The updated account object, or a WP_Error object on failure.
     */
    public function updateAccount( Account $updatedAccount ) {
        if ( !$updatedAccount instanceof Account ) {
            return new WP_Error(404, 'The provided account is not a valid Account instance.');
        }
        if ( !$updatedAccount->getId() ) {
            return new WP_Error(404, 'The account ID is missing.');
        }
        $result = $this->model->updateAccount( $updatedAccount );
        if ( !is_wp_error( $result ) ) {
            $this->accounts[$updatedAccount->getId()] = $result;
        }
        return $this->accounts[$updatedAccount->getId()] ?? $result;
    }

    /**
     * Deletes an account by ID.
     *
     * @param string $accountId The ID of the account to delete.
     *
     * @return bool|WP_Error True if the deletion was successful, false otherwise.
     *                       If an error occurs, a WP_Error object is returned.
     */
    public function deleteAccount( $accountId ) {
        $result = $this->model->deleteAccount( $accountId );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        // $files = Files::getInstance()->getFilesByAccountId($accountId);
        // if (!is_wp_error($files)) {
        //     foreach ($files as $file) {
        //         Files::getInstance()->deleteFile($file['id'], $accountId);
        //     }
        // }
        unset($this->accounts[$accountId]);
        return true;
    }

    public function lostAccount( $accountId ) {
        $result = $this->model->lostAccount( $accountId );
        return $result;
    }

}
