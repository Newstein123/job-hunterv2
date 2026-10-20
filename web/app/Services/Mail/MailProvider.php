<?php

namespace App\Services\Mail;

use App\Models\EmailAccount;

interface MailProvider
{
    /**
     * @param  string  $rawMessage  RFC 822 message
     * @param  string|null  $threadId  provider thread to send into (replies)
     *
     * @throws MailProviderException
     */
    public function send(EmailAccount $account, string $rawMessage, ?string $threadId = null): SentMessage;

    /**
     * Messages in a thread, headers only.
     *
     * @return list<ThreadMessage>
     *
     * @throws MailProviderException
     */
    public function threadMessages(EmailAccount $account, string $threadId): array;

    /**
     * One message with its plain-text body (needs read permission).
     *
     * @throws MailProviderException
     */
    public function message(EmailAccount $account, string $messageId): FullMessage;

    /** Best-effort token revocation when the user disconnects the account. */
    public function revoke(EmailAccount $account): void;
}
