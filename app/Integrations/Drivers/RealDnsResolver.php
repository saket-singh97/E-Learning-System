<?php

namespace App\Integrations\Drivers;

use App\Integrations\DnsResolver;

/**
 * Unlike every other real driver in this app (Bunny, a real AI vendor,
 * Zoom, Razorpay-as-default, AiSensy), this one needs no vendor account —
 * dns_get_record() is a plain PHP builtin. Confirmed callable on this
 * project's native Windows PHP 8.4 build before relying on it (SETUP.md's
 * own Windows-native setup note is why this needed checking rather than
 * assuming, per this block's own plan note) — so this binds as the default
 * driver in AppServiceProvider, not behind a Fake like the vendor-backed
 * ones.
 */
class RealDnsResolver implements DnsResolver
{
    public function txtRecords(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_TXT);

        if ($records === false) {
            return [];
        }

        return array_values(array_filter(array_column($records, 'txt')));
    }
}
