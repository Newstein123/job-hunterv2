<?php

namespace App\Services\Mail;

interface DomainMailChecker
{
    /** true: the domain receives email; false: it can't (no mail servers, or a "null MX"); null: DNS didn't answer. */
    public function acceptsMail(string $domain): ?bool;
}
