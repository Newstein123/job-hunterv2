<?php

namespace App\Services\Mail;

use Illuminate\Support\Facades\Cache;

/**
 * Checks DNS for mail servers (MX, or A/AAAA as the implicit fallback of RFC 5321).
 * A single "." MX record is a null MX (RFC 7505): the domain explicitly accepts no mail.
 */
class DnsDomainMailChecker implements DomainMailChecker
{
    public function acceptsMail(string $domain): ?bool
    {
        $domain = strtolower(rtrim($domain, '.'));

        return Cache::remember("mail-domain:{$domain}", now()->addDay(), function () use ($domain) {
            $mx = @dns_get_record($domain, DNS_MX);
            if ($mx === false) {
                return null; // resolver error: don't block on a DNS hiccup
            }
            if ($mx !== []) {
                $targets = array_filter(array_map(fn ($r) => rtrim((string) ($r['target'] ?? ''), '.'), $mx));

                return $targets !== [];
            }
            $address = @dns_get_record($domain, DNS_A | DNS_AAAA);

            return $address === false ? null : $address !== [];
        });
    }
}
