<?php

namespace CodeConfig\IDB\App;

use function CodeConfig\IDB\ccpidb_fs;
use CodeConfig\IDB\Dropbox\Dropbox;
defined( 'ABSPATH' ) or exit( 'Hey, what are you doing here? You silly human!' );
class API {
    /**
     * Client instance.
     *
     * @var Client
     */
    protected $client;

    /**
     * Dropbox instance.
     *
     * @var Dropbox
     */
    protected $dropbox;

    protected $accountId;

    public function __construct( $accountId ) {
        $client = new Client($accountId);
        $this->client = $client;
        $this->dropbox = $client->getClient();
        $this->accountId = $accountId;
    }

    /**
     * Get the Dropbox client.
     *
     * @return Dropbox
     */
    protected function dropbox() {
        if ( !$this->dropbox ) {
            $this->dropbox = $this->client->getClient();
        }
        return $this->dropbox;
    }

    /**
     * Get the Dropbox client.
     *
     * @return Client
     */
    protected function client() {
        if ( !$this->client ) {
            $this->client = new Client($this->accountId);
        }
        return $this->client;
    }

}
