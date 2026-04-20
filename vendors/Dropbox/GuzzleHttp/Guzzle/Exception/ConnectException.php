<?php

namespace CodeConfig\IDB\Dropbox\GuzzleHttp\Guzzle\Exception;

use CodeConfig\IDB\Dropbox\Psr\Client\NetworkExceptionInterface;
use CodeConfig\IDB\Dropbox\Psr\Message\RequestInterface;

/**
 * Exception thrown when a connection cannot be established.
 *
 * Note that no response is present for a ConnectException
 */
class ConnectException extends TransferException implements NetworkExceptionInterface
{
    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var array
     */
    private $handlerContext;

    /**
     * Constructs a new ConnectException.
     *
     * @param string $message The message of the exception.
     * @param RequestInterface $request The request that caused the exception.
     * @param \Throwable|null $previous The previous exception.
     * @param array $handlerContext The handler context of the exception.
     */
    public function __construct($message, $request, $previous = null, $handlerContext = [])
    {
        parent::__construct($message, 0, $previous);
        $this->request        = $request;
        $this->handlerContext = $handlerContext;
    }

    /**
     * Get the request that caused the exception
     */
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }

    /**
     * Get contextual information about the error from the underlying handler.
     *
     * The contents of this array will vary depending on which handler you are
     * using. It may also be just an empty array. Relying on this data will
     * couple you to a specific handler, but can give more debug information
     * when needed.
     */
    public function getHandlerContext(): array
    {
        return $this->handlerContext;
    }
}
