<?php

namespace App\Services\AiService;

use RuntimeException;

class AiServiceException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }

    /** 4xx means our input was rejected; retrying the same request will not help. */
    public function isRetryable(): bool
    {
        return $this->status === null || $this->status >= 500;
    }
}
