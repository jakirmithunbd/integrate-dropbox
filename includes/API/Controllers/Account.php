<?php

namespace CodeConfig\IDB\API\Controllers;

use CodeConfig\IDB\API\BaseController;
use CodeConfig\IDB\App\Accounts;
use CodeConfig\IDB\App\Client;
use CodeConfig\IDB\Utils\Helpers;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
defined( 'ABSPATH' ) || exit( 'No direct script access allowed' );
class Account extends BaseController {
    public function __construct() {
        parent::__construct( 'integrate-dropbox/v1', 'account' );
    }

    public function register_routes() : void {
        register_rest_route( $this->namespace, "{$this->rest_base}/auth-url", [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'getAuthUrl'],
            'permission_callback' => [$this, 'managePermission'],
            'args'                => [
                'id'        => [
                    'required' => false,
                    'type'     => 'string',
                ],
                'appKey'    => [
                    'required' => false,
                    'type'     => 'string',
                ],
                'appSecret' => [
                    'required' => false,
                    'type'     => 'string',
                ],
            ],
        ] );
        register_rest_route( $this->namespace, "{$this->rest_base}/all", [
            "methods"             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'getAllAccounts'],
            'permission_callback' => [$this, 'manageSettingsPermission'],
        ] );
        register_rest_route( $this->namespace, "{$this->rest_base}/switch", [
            "methods"             => WP_REST_Server::EDITABLE,
            "callback"            => [$this, "switch"],
            "permission_callback" => [$this, "manageSettingsPermission"],
        ] );
        register_rest_route( $this->namespace, "{$this->rest_base}/(?P<id>[^/]+)", [[
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'getAccount'],
            'permission_callback' => [$this, 'manageSettingsPermission'],
        ], [
            "methods"             => WP_REST_Server::DELETABLE,
            "callback"            => [$this, "deleteAccount"],
            "permission_callback" => [$this, "manageSettingsPermission"],
        ]] );
    }

    public function getAuthUrl( WP_REST_Request $request ) : WP_REST_Response {
        try {
            $accountId = $request->get_param( 'id' );
            $appKey = $request->get_param( 'appKey' );
            $appSecret = $request->get_param( 'appSecret' );
            if ( !empty( $appKey ) && !empty( $appSecret ) ) {
                Helpers::updateSetting( 'accounts.appKey', $appKey );
                Helpers::updateSetting( 'accounts.appSecret', $appSecret );
            }
            // If accountId exists, check existing account status
            if ( !empty( $accountId ) && $accountId !== 'null' ) {
                $account = Accounts::getInstance()->syncAccount( $accountId );
                if ( $account instanceof \CodeConfig\IDB\App\Account && (int) $account->getLost() === 0 ) {
                    return $this->successResponse( $account->toArrayData( ['tokens'] ), __( 'Reconnected account successfully', 'integrate-dropbox' ) );
                }
            }
            // Generate new auth URL
            $authUrl = Client::getInstance( 'new' )->getAuthUrl();
            if ( empty( $authUrl ) ) {
                return $this->errorResponse( __( 'Auth URL could not be generated', 'integrate-dropbox' ), self::HTTP_NOT_FOUND );
            }
            return $this->successResponse( $authUrl, __( 'Auth URL retrieved successfully', 'integrate-dropbox' ) );
        } catch ( \Exception $e ) {
            return $this->handleException( $e, 'Failed to retrieve auth URL' );
        }
    }

    public function getAllAccounts( WP_REST_Request $request ) : WP_REST_Response {
        try {
            $accounts = Accounts::getInstance()->getAccounts( ARRAY_N, ['tokens'] );
            if ( empty( $accounts ) ) {
                return $this->errorResponse( 'No account found', self::HTTP_NOT_FOUND );
            }
            return $this->successResponse( $accounts, 'Accounts retrieved successfully' );
        } catch ( \Exception $e ) {
            return $this->handleException( $e, 'Failed to retrieve accounts' );
        }
    }

    public function getAccount( WP_REST_Request $request ) : WP_REST_Response {
        try {
            $accountId = $request->get_param( 'id' );
            $account = Accounts::getInstance()->getAccount( $accountId );
            if ( empty( $account ) ) {
                return $this->errorResponse( 'No account found', self::HTTP_NOT_FOUND );
            }
            return $this->successResponse( $account->toArrayData( ['tokens'] ), 'Account retrieved successfully' );
        } catch ( \Exception $e ) {
            return $this->handleException( $e, 'Failed to retrieve account' );
        }
    }

    public function deleteAccount( WP_REST_Request $request ) : WP_REST_Response {
        try {
            $accountId = $request->get_param( 'id' );
            $result = Accounts::getInstance()->deleteAccount( $accountId );
            if ( is_wp_error( $result ) ) {
                return $this->errorResponse( $result->get_error_message(), $result->get_error_code() );
            }
            return $this->successResponse( $result, 'Account deleted successfully' );
        } catch ( \Exception $e ) {
            return $this->handleException( $e, 'Failed to delete account' );
        }
    }

    public function switch( WP_REST_Request $request ) : WP_REST_Response {
        try {
            $accountId = $request->get_param( 'id' );
            $account = Accounts::getInstance()->syncAccount( $accountId );
            if ( $account instanceof \CodeConfig\IDB\App\Account && (int) $account->getLost() === 0 ) {
                return $this->successResponse( $account->toArrayData( ['tokens'] ), __( 'Reconnected account successfully', 'integrate-dropbox' ) );
            }
            return $this->errorResponse( 'Failed to switch account', 400 );
        } catch ( \Exception $e ) {
            return $this->handleException( $e, 'Failed to switch account' );
        }
    }

}
