<?php

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\DnsResolver;

/**
 * DNS-verification-only (design doc §2.4 decision 3) — actually serving
 * HTTPS on a verified custom domain stays a documented manual step
 * (SETUP.md), this class only proves the tenant controls the domain's own
 * DNS and lets ResolveTenant recognize it.
 *
 * The verification token is derived (HMAC of tenant id + domain, this
 * app's own APP_KEY), not a stored column — the design doc's own schema
 * for this block lists exactly two new columns (custom_domain,
 * custom_domain_verified_at); a third "verification_token" column would
 * just be a value this class can already recompute on demand from data it
 * already has.
 */
class CustomDomainService
{
    private const TXT_PREFIX = 'lms-domain-verify=';

    public function __construct(private DnsResolver $dns) {}

    public function setDomain(Tenant $tenant, ?string $domain): void
    {
        // custom_domain_verified_at is deliberately not in Tenant's
        // $fillable (never settable via mass assignment/a form) — update()
        // would silently drop it here, leaving a *previous* domain's
        // verified_at stuck even though the domain just changed. forceFill()
        // bypasses the guard for this one programmatic write, same pattern
        // User::markEmailAsVerified() already uses for its own non-fillable
        // timestamp.
        $tenant->forceFill([
            'custom_domain' => $domain,
            'custom_domain_verified_at' => null,
        ])->save();
    }

    /**
     * A TXT record on `_lms-verify.{domain}` rather than the bare apex —
     * the apex commonly already carries SPF/other TXT records, and a
     * dedicated challenge host avoids colliding with (or being confused
     * with) any of them.
     */
    public function challengeHost(Tenant $tenant): string
    {
        return '_lms-verify.'.$tenant->custom_domain;
    }

    public function verificationToken(Tenant $tenant): string
    {
        return self::TXT_PREFIX.substr(
            hash_hmac('sha256', $tenant->id.'|'.$tenant->custom_domain, config('app.key')),
            0,
            32,
        );
    }

    public function verify(Tenant $tenant): bool
    {
        if (blank($tenant->custom_domain)) {
            return false;
        }

        $expected = $this->verificationToken($tenant);
        $records = $this->dns->txtRecords($this->challengeHost($tenant));

        if (! in_array($expected, $records, true)) {
            return false;
        }

        $tenant->forceFill(['custom_domain_verified_at' => now()])->save();

        return true;
    }
}
