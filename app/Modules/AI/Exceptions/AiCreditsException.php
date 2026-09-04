<?php

namespace App\Modules\AI\Exceptions;

use RuntimeException;

class AiCreditsException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus = 402,
    ) {
        parent::__construct($message);
    }
}
