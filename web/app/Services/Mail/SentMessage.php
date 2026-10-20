<?php

namespace App\Services\Mail;

final readonly class SentMessage
{
    public function __construct(public string $id, public string $threadId) {}
}
