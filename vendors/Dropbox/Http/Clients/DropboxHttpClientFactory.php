<?php

namespace CodeConfig\IDB\Dropbox\Http\Clients;

use CodeConfig\IDB\Dropbox\GuzzleHttp\Guzzle\Client as Guzzle;
use InvalidArgumentException;

/**
 * DropboxHttpClientFactory
 */
class DropboxHttpClientFactory
{
    /**
     * Make HTTP Client
     *
     * @param \CodeConfig\IDB\Dropbox\Http\Clients\DropboxHttpClientInterface|\CodeConfig\IDB\Dropbox\GuzzleHttp\Guzzle\Client|null $handler
     *
     * @return \CodeConfig\IDB\Dropbox\Http\Clients\DropboxHttpClientInterface
     */
    public static function make($handler)
    {
        //No handler specified
        if (! $handler) {
            return new DropboxGuzzleHttpClient();
        }

        //Custom Implementation, maybe.
        if ($handler instanceof DropboxHttpClientInterface) {
            return $handler;
        }

        //Handler is a custom configured Guzzle Client
        if ($handler instanceof Guzzle) {
            return new DropboxGuzzleHttpClient($handler);
        }

        //Invalid handler
        throw new InvalidArgumentException('The http client handler must be an instance of GuzzleHttp\Client or an instance of CodeConfig\IDB\Dropbox\Http\Clients\DropboxHttpClientInterface.');
    }
}
