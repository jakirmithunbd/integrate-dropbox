<?php

namespace CodeConfig\IDB\Dropbox\GuzzleHttp\Guzzle;

use CodeConfig\IDB\Dropbox\Psr\Message\MessageInterface;

final class BodySummarizer implements BodySummarizerInterface
{
    /**
     * @var int|null
     */
    private $truncateAt;

    public function __construct($truncateAt = null)
    {
        $this->truncateAt = $truncateAt;
    }

    /**
     * Returns a summarized message body.
     */
    public function summarize(MessageInterface $message): ?string
    {
        return $this->truncateAt === null
        ? \CodeConfig\IDB\Dropbox\GuzzleHttp\Psr7\Message::bodySummary($message)
        : \CodeConfig\IDB\Dropbox\GuzzleHttp\Psr7\Message::bodySummary($message, $this->truncateAt);
    }
}
