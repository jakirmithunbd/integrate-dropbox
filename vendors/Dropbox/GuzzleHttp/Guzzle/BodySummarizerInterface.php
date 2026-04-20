<?php

namespace CodeConfig\IDB\Dropbox\GuzzleHttp\Guzzle;

use CodeConfig\IDB\Dropbox\Psr\Message\MessageInterface;

interface BodySummarizerInterface
{
    /**
     * Returns a summarized message body.
     */
    public function summarize(MessageInterface $message): ?string;
}
