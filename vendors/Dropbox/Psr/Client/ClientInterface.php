<?php

namespace CodeConfig\IDB\Dropbox\Psr\Client;

use CodeConfig\IDB\Dropbox\Psr\Message\RequestInterface;
use CodeConfig\IDB\Dropbox\Psr\Message\ResponseInterface;

interface ClientInterface
{
    /**
     * Sends a PSR-7 request and returns a PSR-7 response.
     *
     * @param RequestInterface $request
     *
     * @return ResponseInterface
     *
     * @throws \CodeConfig\IDB\Dropbox\Psr\Client\ExceptionInterface If an error happens while processing the request.
     */
    public function sendRequest(RequestInterface $request): ResponseInterface;
}
