<?php

namespace App\Modules\Social\Exceptions;

use Illuminate\Http\Client\Response;

/**
 * A failed X request, classified so callers can decide safely.
 *
 * `definite` means X certainly did not create the post, so trying again
 * cannot publish it twice. After a timeout, a 5xx or a success without an ID
 * the outcome is unknown: X may have published and charged for the post, so
 * it must never be sent again automatically.
 */
class XPublishException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $category,
        public readonly bool $definite = true,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response, bool $creating = false): self
    {
        $status = $response->status();

        return match (true) {
            $status === 401 => new self('Reconnect your X account.', 'reconnect'),
            // X reports an empty or capped credit balance as 402, or as 403
            // mentioning credits. Provider text is never shown to clients.
            $status === 402, $status === 403 && preg_match('/credit|balance|payment|spend/i', $response->body()) === 1 => new self('X API credits are unavailable. Contact your administrator.', 'credit'),
            $status === 403 => new self('X denied this post. Contact your administrator to check the X app permissions.', 'permission'),
            $status === 429 => new self('X rate limit reached. Try again later.', 'rate_limit'),
            $status >= 500 && $creating => new self('X did not confirm this post. Check X before publishing it again.', 'unknown', false),
            default => new self('X rejected this request (HTTP '.$status.').', 'provider'),
        };
    }
}
