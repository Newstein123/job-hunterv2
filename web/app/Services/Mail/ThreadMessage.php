<?php

namespace App\Services\Mail;

use Carbon\CarbonImmutable;

final readonly class ThreadMessage
{
    /**
     * @param  list<string>  $labels
     * @param  array<string, string>  $headers  lowercase header name => value
     */
    public function __construct(
        public string $id,
        public string $from,
        public array $labels,
        public CarbonImmutable $receivedAt,
        public array $headers = [],
    ) {}

    public function isFromMe(): bool
    {
        return in_array('SENT', $this->labels, true);
    }

    public function isBounce(): bool
    {
        return (bool) preg_match('/mailer-daemon|postmaster/i', $this->from);
    }

    /** Out-of-office and other automatic responses (RFC 3834 headers, common vendor headers, subject). */
    public function isAutoReply(): bool
    {
        $h = $this->headers;
        $autoSubmitted = strtolower($h['auto-submitted'] ?? 'no');

        return ($autoSubmitted !== '' && $autoSubmitted !== 'no')
            || isset($h['x-autoreply']) || isset($h['x-autorespond'])
            || in_array(strtolower($h['precedence'] ?? ''), ['auto_reply', 'bulk', 'junk'], true)
            || (bool) preg_match('/^(automatic reply|auto(-|\s)?reply|out of (the )?office)/i', $h['subject'] ?? '');
    }
}
