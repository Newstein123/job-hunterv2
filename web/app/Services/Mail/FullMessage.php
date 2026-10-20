<?php

namespace App\Services\Mail;

final readonly class FullMessage
{
    /** @param array<string, string> $headers lowercase header name => value */
    public function __construct(
        public string $subject,
        public string $bodyText,
        public string $snippet,
        public array $headers,
    ) {}
}
