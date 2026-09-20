<?php

namespace App\Modules\AI\Services;

class KnowledgeUrlResolutionException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
