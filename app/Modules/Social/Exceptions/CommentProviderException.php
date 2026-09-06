<?php

namespace App\Modules\Social\Exceptions;

class CommentProviderException extends \RuntimeException
{
    public function __construct(public string $reason, public int $retryAfter = 60)
    {
        parent::__construct($reason);
    }
}
