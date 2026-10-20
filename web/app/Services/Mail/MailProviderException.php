<?php

namespace App\Services\Mail;

use RuntimeException;

class MailProviderException extends RuntimeException
{
    /** Token revoked/expired or a scope is missing: the user must reconnect the account. */
    public const AUTH = 'auth';

    /** The provider is limiting this account (quota, sending limits): stop using it. */
    public const RESTRICTED = 'restricted';

    /** Temporary: rate limited or a provider outage. Safe to retry later. */
    public const TRANSIENT = 'transient';

    /** The message itself was rejected (e.g. invalid recipient). Retrying will not help. */
    public const PERMANENT = 'permanent';

    public function __construct(string $message, public readonly string $kind)
    {
        parent::__construct($message);
    }
}
