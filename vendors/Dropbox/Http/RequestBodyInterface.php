<?php

namespace CodeConfig\IDB\Dropbox\Http;

/**
 * RequestBodyInterface
 */
interface RequestBodyInterface
{
    /**
     * Get the Body of the Request
     *
     * @return string|resource|\CodeConfig\IDB\Dropbox\Psr\Message\StreamInterface
     */
    public function getBody();
}
