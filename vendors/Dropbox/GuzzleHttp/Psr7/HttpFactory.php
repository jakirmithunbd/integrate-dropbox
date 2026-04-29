<?php

declare (strict_types=1);

namespace CodeConfig\IDB\Dropbox\GuzzleHttp\Psr7;

use CodeConfig\IDB\Dropbox\Psr\Factory\RequestFactoryInterface;
use CodeConfig\IDB\Dropbox\Psr\Factory\ResponseFactoryInterface;
use CodeConfig\IDB\Dropbox\Psr\Factory\ServerRequestFactoryInterface;
use CodeConfig\IDB\Dropbox\Psr\Factory\StreamFactoryInterface;
use CodeConfig\IDB\Dropbox\Psr\Factory\UploadedFileFactoryInterface;
use CodeConfig\IDB\Dropbox\Psr\Factory\UriFactoryInterface;
use CodeConfig\IDB\Dropbox\Psr\Message\RequestInterface;
use CodeConfig\IDB\Dropbox\Psr\Message\ResponseInterface;
use CodeConfig\IDB\Dropbox\Psr\Message\ServerRequestInterface;
use CodeConfig\IDB\Dropbox\Psr\Message\StreamInterface;
use CodeConfig\IDB\Dropbox\Psr\Message\UploadedFileInterface;
use CodeConfig\IDB\Dropbox\Psr\Message\UriInterface;

/**
 * Implements all of the PSR-17 interfaces.
 *
 * Note: in consuming code it is recommended to require the implemented interfaces
 * and inject the instance of this class multiple times.
 */
final class HttpFactory implements RequestFactoryInterface, ResponseFactoryInterface, ServerRequestFactoryInterface, StreamFactoryInterface, UploadedFileFactoryInterface, UriFactoryInterface
{
    public function createUploadedFile(
        StreamInterface $stream,
        int $size = null,
        int $error = \UPLOAD_ERR_OK,
        string $clientFilename = null,
        string $clientMediaType = null
    ): UploadedFileInterface {
        if ($size === null) {
            $size = $stream->getSize();
        }

        return new UploadedFile($stream, $size, $error, $clientFilename, $clientMediaType);
    }

    public function createStream(string $content = ''): StreamInterface
    {
        return Utils::streamFor($content);
    }

    public function createStreamFromFile(string $file, string $mode = 'r'): StreamInterface
    {
        try {
            $resource = Utils::tryFopen($file, $mode);
        } catch (\RuntimeException $e) {
            if ('' === $mode || false === \in_array($mode[0], ['r', 'w', 'a', 'x', 'c'], true)) {
                throw new \InvalidArgumentException(sprintf('Invalid file opening mode "%s"', esc_html($mode)), 0, $e); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }

            throw $e;
        }

        return Utils::streamFor($resource);
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        return Utils::streamFor($resource);
    }

    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
    {
        if (empty($method)) {
            if (! empty($serverParams['REQUEST_METHOD'])) {
                $method = $serverParams['REQUEST_METHOD'];
            } else {
                throw new \InvalidArgumentException('Cannot determine HTTP method');
            }
        }

        return new ServerRequest($method, $uri, [], null, '1.1', $serverParams);
    }

    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new Response($code, [], null, '1.1', $reasonPhrase);
    }

    public function createRequest(string $method, $uri): RequestInterface
    {
        return new Request($method, $uri);
    }

    public function createUri(string $uri = ''): UriInterface
    {
        return new Uri($uri);
    }
}
