<?php

namespace App\Services\Mail;

/**
 * The newest part of an email reply: drops the quoted history ("On … wrote:", "> …",
 * "-----Original Message-----", Outlook "From: … Sent: …" blocks).
 */
class ReplyText
{
    public static function latest(string $body): string
    {
        $lines = preg_split('/\R/', $body) ?: [];
        $kept = [];
        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            if (preg_match('/^On .{4,200} wrote:$/i', $trimmed)
                || preg_match('/^-{2,}\s*(Original Message|Forwarded message)/i', $trimmed)
                || (preg_match('/^From:\s/i', $trimmed) && preg_match('/^(Sent|Date):\s/i', trim($lines[$i + 1] ?? '')))
                || str_starts_with($trimmed, '>')) {
                break;
            }
            $kept[] = $line;
        }

        return trim(implode("\n", $kept)) ?: trim($body);
    }
}
