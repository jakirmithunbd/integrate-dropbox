<?php

namespace CodeConfig\IDB\Models;

defined( 'ABSPATH' ) || exit( 'No direct script access allowed' );
// phpcs:disable WordPress.DB.DirectDatabaseQuery
use CodeConfig\IDB\App\Account as AppAccount;
use CodeConfig\IDB\App\Accounts;
use function CodeConfig\IDB\ccpidb_fs;
use CodeConfig\IDB\Utils\Singleton;
use WP_Error;
class Account extends BaseModel {
    use Singleton;
    /**
     * Constructor to initialize the model with database access
     */
    public function __construct() {
        parent::__construct( 'ccpidb_accounts' );
    }

    /**
     * Get all accounts from the database
     *
     * @return array|WP_Error
     */
    public function getAccounts() {
        $result = $this->findMultipleRecords( "SELECT * FROM %i", [$this->tableName], ARRAY_A );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return $this->processAccounts( $result );
    }

    /**
     * Get account by ID
     *
     * @param string|null $id
     * @return AppAccount|WP_Error|false
     */
    public function getAccount( $id = null ) {
        if ( empty( $id ) ) {
            $result = $this->findSingleRecord( "SELECT * FROM {$this->tableName} WHERE `active` = %d LIMIT 1", [1] );
        } else {
            $result = $this->findSingleRecord( "SELECT * FROM {$this->tableName} WHERE id = %s", [$id] );
        }
        if ( is_wp_error( $result ) || empty( $result ) ) {
            return $result;
        }
        return $this->processAccount( $result );
    }

    /**
     * Retrieve an account by its key.
     *
     * @param string $key The key of the account to retrieve.
     * @return AppAccount|WP_Error|false The account object if found, or a WP_Error object if not found.
     */
    public function getAccountByKey( $key ) {
        $result = $this->findSingleRecord( "SELECT * FROM {$this->tableName} WHERE `accountKey` = %s LIMIT 1", [$key] );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return $this->processAccount( $result );
    }

    /**
     * Add a new account to the database
     *
     * @param AppAccount $account
     * @return bool|WP_Error
     */
    public function addAccount( AppAccount $account ) {
        // if (!current_user_can(CCPIGD_ACCESS_CAP)) {
        //     return false;
        // }
        $accountsCount = $this->getAccountCount();
        $decisionToActive = ( $accountsCount == 0 ? 1 : 0 );
        $data = [
            'id'            => $account->getId(),
            'accountKey'    => $account->getKey(),
            'name'          => maybe_serialize( $account->getName() ),
            'email'         => $account->getEmail(),
            'photo'         => $account->getPhoto(),
            'storage'       => maybe_serialize( $account->getStorage() ),
            'lost'          => (int) $account->getLost(),
            'rootInfo'      => maybe_serialize( $account->getRootInfo() ),
            'userId'        => $account->getUser(),
            'active'        => (int) $decisionToActive,
            'tokens'        => maybe_serialize( $account->getAccessToken( ARRAY_A ) ),
            'emailVerified' => (int) $account->isEmailVerified(),
            'disabled'      => (int) $account->isDisabled(),
            'country'       => $account->getCountry(),
            'locale'        => $account->getLocale(),
            'type'          => $account->getAccountType(),
            'referralLink'  => $account->getReferralLink(),
            'isPaired'      => (int) $account->isPaired(),
            'isTeam'        => (int) $account->isTeam(),
            'createdAt'     => current_time( 'mysql' ),
            'updatedAt'     => current_time( 'mysql' ),
        ];
        $format = [
            '%s',
            // id
            '%s',
            // accountKey
            '%s',
            // name
            '%s',
            // email
            '%s',
            // photo
            '%s',
            // storage
            '%d',
            // lost
            '%s',
            // rootInfo
            '%d',
            // userId
            '%d',
            // active
            '%s',
            // tokens
            '%d',
            // emailVerified
            '%d',
            // disabled
            '%s',
            // country
            '%s',
            // locale
            '%s',
            // type
            '%s',
            // referralLink
            '%d',
            // isPaired
            '%d',
            // isTeam
            '%s',
            // createdAt
            '%s',
        ];
        $isExistingAccount = $this->getAccount( $data['id'] );
        if ( is_wp_error( $isExistingAccount ) ) {
            return $isExistingAccount;
        }
        if ( $isExistingAccount ) {
            unset($data['id']);
            unset($data['accountKey']);
            unset($data['createdAt']);
            $data['updatedAt'] = current_time( 'mysql' );
            $updateFormat = [
                '%s',
                // name
                '%s',
                // email
                '%s',
                // photo
                '%s',
                // storage
                '%d',
                // lost
                '%s',
                // rootInfo
                '%d',
                // userId
                '%d',
                // active
                '%s',
                // tokens
                '%d',
                // emailVerified
                '%d',
                // disabled
                '%s',
                // country
                '%s',
                // locale
                '%s',
                // type
                '%s',
                // referralLink
                '%d',
                // isPaired
                '%d',
                // isTeam
                '%s',
            ];
            $where = [
                'id' => $account->getId(),
            ];
            $whereFormat = ['%s'];
            $result = $this->updateRecords(
                $data,
                $where,
                $updateFormat,
                $whereFormat
            );
        } else {
            $result = $this->createRecord( $data, $format );
        }
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return (bool) $result;
    }

    /**
     * Update account in the database
     *
     * @param AppAccount $account
     * @return AppAccount|WP_Error
     */
    public function updateAccount( AppAccount $account ) {
        // if (!current_user_can(CCPIGD_ACCESS_CAP)) {
        //     return new WP_Error(401, __('You do not have permission to update accounts.', 'integrate-dropbox'));
        // }
        $data = [
            'name'          => maybe_serialize( $account->getName() ),
            'email'         => $account->getEmail(),
            'photo'         => $account->getPhoto(),
            'storage'       => maybe_serialize( $account->getStorage() ),
            'lost'          => (int) $account->getLost(),
            'rootInfo'      => maybe_serialize( $account->getRootInfo() ),
            'userId'        => $account->getUser(),
            'active'        => (int) $account->getActive(),
            'tokens'        => maybe_serialize( $account->getAccessToken( ARRAY_A ) ),
            'emailVerified' => (int) $account->isEmailVerified(),
            'disabled'      => (int) $account->isDisabled(),
            'country'       => $account->getCountry(),
            'locale'        => $account->getLocale(),
            'type'          => $account->getAccountType(),
            'referralLink'  => $account->getReferralLink(),
            'isPaired'      => (int) $account->isPaired(),
            'updatedAt'     => current_time( 'mysql' ),
        ];
        $format = [
            '%s',
            // name
            '%s',
            // email
            '%s',
            // photo
            '%s',
            // storage
            '%d',
            // lost
            '%s',
            // rootInfo
            '%d',
            // userId
            '%d',
            // active
            '%s',
            // tokens
            '%d',
            // emailVerified
            '%d',
            // disabled
            '%s',
            // country
            '%s',
            // locale
            '%s',
            // type
            '%s',
            // referralLink
            '%d',
            // isPaired
            '%s',
        ];
        $where = [
            'id' => $account->getId(),
        ];
        $whereFormat = ['%s'];
        $result = $this->updateRecords(
            $data,
            $where,
            $format,
            $whereFormat,
            ARRAY_A
        );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( empty( $result ) ) {
            return new WP_Error(400, __( 'Failed to update account.', 'integrate-dropbox' ));
        }
        return $this->processAccount( $result );
    }

    /**
     * Delete an account by ID
     *
     * @param int|string $id
     * @return bool|WP_Error
     */
    public function deleteAccount( $id ) {
        $account = $this->getAccount( $id );
        if ( is_wp_error( $account ) ) {
            return $account;
        }
        // first delete all files associated with this account
        $filesModel = Files::getInstance();
        $filesModel->deleteFilesByAccount( $id );
        if ( is_wp_error( $filesModel ) ) {
            return $filesModel;
        }
        $result = $this->deleteRecords( [
            'id' => $id,
        ], ['%s'] );
        if ( $result === false ) {
            return new WP_Error(400, __( 'Failed to delete account.', 'integrate-dropbox' ));
        }
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return (bool) $result;
    }

    /**
     * Sets the specified account as lost.
     *
     * @param string|int $id The ID of the account to set as lost.
     * @return bool|WP_Error True if the account was successfully set as lost, false otherwise.
     *                       If an error occurred, a WP_Error object is returned.
     */
    public function lostAccount( $id ) {
        if ( empty( $id ) ) {
            return false;
        }
        $result = $this->updateRecords(
            [
                'lost'   => 1,
                'active' => 0,
            ],
            [
                'id' => $id,
            ],
            ['%d', '%d'],
            ['%s']
        );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return (bool) $result;
    }

    /**
     * Checks if the specified account is lost.
     *
     * @param string|int $id The ID of the account to check.
     *
     * @return bool True if the account is lost, false otherwise.
     *              If the account does not exist, an error occurred, or the user does not have permission, false is returned.
     */
    public function isLost( $id ) {
        if ( empty( $id ) ) {
            return false;
        }
        $result = $this->getAccount( $id )->getLost() == 1;
        if ( is_wp_error( $result ) ) {
            return false;
        }
        return (bool) $result;
    }

    /**
     * Get tokens for a given account ID
     *
     * @param int|string|null $id Optional account ID
     * @return array|false|WP_Error
     */
    public function getTokens( $id = null ) {
        // if (!current_user_can(CCPIGD_ACCESS_CAP)) {
        //     return new WP_Error(401, __('You do not have permission to retrieve tokens.', 'integrate-dropbox'));
        // }
        if ( empty( $id ) ) {
            $account = $this->getAccount();
            if ( is_wp_error( $account ) ) {
                return $account;
            }
            if ( $account instanceof AppAccount ) {
                $id = $account->getId();
            }
        }
        if ( empty( $id ) || !is_numeric( $id ) ) {
            return new WP_Error(400, __( 'Account ID is required.', 'integrate-dropbox' ));
        }
        $result = $this->findSingleRecord( "SELECT tokens FROM {$this->tableName} WHERE id = %s", [$id], ARRAY_A );
        if ( is_wp_error( $result ) || empty( $result ) ) {
            return $result;
        }
        return maybe_unserialize( $result['tokens'] );
    }

    /**
     * Updates the token for a given account ID.
     *
     * Validates user permissions and checks that the provided ID and token are
     * non-empty and valid. Then serializes the token and updates it in the database.
     * Logs any database errors encountered during the update process.
     *
     * @param string $id The account ID for which the token is being updated.
     * @param array $token The token to be set for the account.
     * @return bool|WP_Error True if the token was successfully updated, false otherwise.
     */
    public function setToken( $id, $token ) {
        // if (!current_user_can(CCPIGD_ACCESS_CAP)) {
        //     return new WP_Error(401, __('You do not have permission to update tokens.', 'integrate-dropbox'));
        // }
        if ( empty( $id ) || empty( $token ) ) {
            return new WP_Error(400, __( 'Account ID and token are required.', 'integrate-dropbox' ));
        }
        $data = [
            'tokens'    => maybe_serialize( $token ),
            'updatedAt' => current_time( 'mysql' ),
        ];
        $where = [
            'id' => $id,
        ];
        $format = ['%s', '%s'];
        $whereFormat = ['%s'];
        $result = $this->updateRecords(
            $data,
            $where,
            $format,
            $whereFormat
        );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return $result !== false;
    }

    /**
     * Process a single account object
     *
     * @param array $account
     * @return AppAccount|bool
     */
    private function processAccount( array $account ) {
        if ( empty( $account ) ) {
            return false;
        }
        $account['name'] = ( maybe_unserialize( $account['name'] ) ?: [] );
        $account['storage'] = ( maybe_unserialize( $account['storage'] ) ?: [] );
        $account['tokens'] = ( maybe_unserialize( $account['tokens'] ) ?: [] );
        $account['rootInfo'] = ( maybe_unserialize( $account['rootInfo'] ) ?: [] );
        //convert integer fields
        $account['userId'] = (int) $account['userId'];
        return new AppAccount($account);
    }

    /**
     * Process an array of account objects
     *
     * @param array $accounts
     * @return array
     */
    private function processAccounts( array $accounts ) {
        $processAccounts = array_map( [$this, 'processAccount'], $accounts );
        $accountsById = [];
        foreach ( $processAccounts as $processedAccount ) {
            if ( $processedAccount ) {
                $accountId = $processedAccount->getId();
                if ( $accountId !== null ) {
                    $accountsById[$accountId] = $processedAccount;
                }
            }
        }
        // if (!ccpigd_fs()->can_use_premium_code()) {
        //     $filterAccounts = array_filter($accountsById, function ($account) {
        //         return !empty($account->getActive());
        //     });
        //     return array_values($filterAccounts);
        // }
        return $accountsById;
    }

    /**
     * Check if an account exists by ID
     *
     * @param string|int $id Account ID
     * @return bool|WP_Error True if account exists, false otherwise
     */
    public function accountExists( $id ) {
        if ( empty( $id ) ) {
            return false;
        }
        return $this->recordExists( [
            'id' => $id,
        ], ['%s'] );
    }

    /**
     * Get account count
     *
     * @return int|WP_Error Number of accounts or WP_Error on failure
     */
    public function getAccountCount() {
        return $this->countRecords();
    }

    /**
     * Get active account ID
     *
     * @return string|null|WP_Error Active account ID or null if none
     */
    public function getActiveAccountId() {
        $result = $this->getColumnValue( 'id', [
            'active' => 1,
        ], ['%d'] );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return $result;
    }

    /**
     * Check if account is valid (exists and not lost)
     *
     * @param string|int $id Account ID
     * @return bool True if account is valid, false otherwise
     */
    public function isAccountValid( $id ) {
        return $this->isValidAccount( $id );
    }

    /**
     * Get accounts with pagination
     *
     * @param int $page Page number (default: 1)
     * @param int $perPage Items per page (default: 10)
     * @param string $orderBy Order by column (default: 'createdAt')
     * @param string $order Order direction (default: 'DESC')
     * @return array|WP_Error Array of accounts or WP_Error on failure
     */
    public function getAccountsPaginated(
        $page = 1,
        $perPage = 10,
        $orderBy = 'createdAt',
        $order = 'DESC'
    ) {
        $allowedOrderBy = [
            'id',
            'name',
            'email',
            'createdAt',
            'updatedAt',
            'active'
        ];
        $orderBy = $this->sanitizeOrderBy( $orderBy, $allowedOrderBy );
        $order = $this->sanitizeOrder( $order );
        $pagination = $this->sanitizePagination( $page, $perPage );
        $sql = "SELECT * FROM {$this->tableName} ORDER BY `{$orderBy}` {$order} LIMIT %d OFFSET %d";
        $result = $this->findMultipleRecords( $sql, [$pagination['perPage'], $pagination['offset']] );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return $this->processAccounts( $result );
    }

}

// phpcs:enable WordPress.DB.DirectDatabaseQuery