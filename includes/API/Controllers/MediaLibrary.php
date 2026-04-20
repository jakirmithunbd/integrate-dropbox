<?php

namespace CodeConfig\IDB\API\Controllers;

use CodeConfig\IDB\API\BaseController;
use CodeConfig\IDB\App\App;
use function CodeConfig\IDB\ccpidb_fs;
use CodeConfig\IDB\Integrations\MediaLibrary__premium_only as IntegrationsMediaLibrary;
use CodeConfig\IDB\Integrations\MediaLibrary__premium_only\Migration;
use CodeConfig\IDB\Models\Attachment;
use CodeConfig\IDB\Utils\Helpers;
use Exception;
use function in_array;
use function is_array;
use MasterStudy\Lms\Plugin\Media;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
defined( 'ABSPATH' ) || exit( 'No direct script access allowed' );
class MediaLibrary extends BaseController {
    public function __construct() {
        parent::__construct( 'integrate-dropbox/v1', 'media-library' );
    }

    public function register_routes() : void {
        // Clear all attachments.
        register_rest_route( $this->namespace, $this->rest_base . '/clear', [[
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [$this, 'deleteAttachment'],
            'permission_callback' => [$this, 'checkPermission'],
            'args'                => [],
        ]] );
    }

    /**
     * Clear all Dropbox attachments from Media Library.
     *
     * @param WP_REST_Request $request REST request object.
     * @return WP_REST_Response REST response object.
     */
    public function deleteAttachment( WP_REST_Request $request ) : WP_REST_Response {
        try {
            Attachment::clearAttachments();
            return $this->successResponse( [], __( 'All attachments cleared successfully.', 'integrate-dropbox' ) );
        } catch ( Exception $e ) {
            return $this->errorResponse( $e->getMessage(), self::HTTP_INTERNAL_SERVER_ERROR );
        }
    }

}
