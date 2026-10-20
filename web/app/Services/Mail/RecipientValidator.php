<?php

namespace App\Services\Mail;

use App\Models\EmailAccount;
use App\Models\EmailMessage;

/**
 * Pre-send checks for every recipient (To, CC, BCC). These are the checks that can be done
 * reliably; no service can confirm a specific mailbox exists without emailing it.
 */
class RecipientValidator
{
    /** RFC 2606 / 6761 names that never receive real mail. */
    private const RESERVED = ['example.com', 'example.net', 'example.org', 'example', 'test', 'invalid', 'localhost'];

    public function __construct(private readonly DomainMailChecker $dns) {}

    /** @return list<string> human-readable problems; empty when the address passes */
    public function problems(string $email, int $userId): array
    {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ["{$email} is not a valid email address"];
        }
        $domain = substr((string) strrchr($email, '@'), 1);

        if ($this->isReserved($domain)) {
            return ["{$email} uses a test domain ({$domain}) that never receives email"];
        }
        if (EmailAccount::where('user_id', $userId)->where('email', $email)->exists()) {
            return ["{$email} is one of your own connected accounts"];
        }
        if ($this->bouncedBefore($email, $userId)) {
            return ["{$email} bounced before; check the address"];
        }
        if ($this->dns->acceptsMail($domain) === false) {
            return ["{$domain} does not receive email (no mail servers found); check for a typo"];
        }

        return [];
    }

    /**
     * @param  list<string>  $emails
     * @return list<string>
     */
    public function problemsForAll(array $emails, int $userId): array
    {
        return array_merge([], ...array_map(fn ($e) => $this->problems($e, $userId), $emails));
    }

    private function isReserved(string $domain): bool
    {
        foreach (self::RESERVED as $reserved) {
            if ($domain === $reserved || str_ends_with($domain, '.'.$reserved)) {
                return true;
            }
        }

        return false;
    }

    private function bouncedBefore(string $email, int $userId): bool
    {
        return EmailMessage::where('user_id', $userId)->where('direction', EmailMessage::OUTBOUND)
            ->where('to_email', $email)->where('last_error', 'like', EmailMessage::BOUNCE_PREFIX.'%')->exists();
    }
}
