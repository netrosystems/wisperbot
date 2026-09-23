<?php

namespace App\Modules\Social\Exceptions;

/**
 * The request that creates a post may have succeeded: it timed out, the
 * provider answered 5xx, or it answered success without an id. Sending it
 * again automatically could publish the post twice, so the publisher keeps
 * the link's attempt marker and waits for the client to check and decide.
 */
class PublishOutcomeUnknownException extends \RuntimeException
{
    public function __construct(public readonly string $network, ?\Throwable $previous = null)
    {
        parent::__construct("The {$network} publish request did not return a confirmed result.", 0, $previous);
    }
}
