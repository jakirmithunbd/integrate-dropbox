<?php

namespace CodeConfig\IDB\Dropbox\Http\Clients;

/**
 * DropboxHttpClientInterface
 */
interface DropboxHttpClientInterface
{
    /**
     * Send request to the server and fetch the raw response
     *
     * @param string $url URL/Endpoint to send the request to
     * @param string $method Request Method
     * @param string|resource|\CodeConfig\IDB\Dropbox\Psr\Message\StreamInterface|null $body Request Body
     * @param array $headers Request Headers
     * @param array $options Additional Options
     *
     * @return \CodeConfig\IDB\Dropbox\Http\DropboxRawResponse Raw response from the server
     *
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     */
    public function send($url, $method, $body, $headers = [], $options = []);
}
