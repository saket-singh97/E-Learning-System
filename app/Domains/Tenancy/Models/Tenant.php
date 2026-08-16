<?php

namespace App\Domains\Tenancy\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    /**
     * A flat enum, not a configurable-limits table — S9 B12's own design
     * note (§2.4 decision 5): a tenant-level tier plus two hardcoded
     * per-tier limits is enough to turn S2/S3's measurement-only bandwidth/
     * AI-spend tracking into real enforcement. A richer tiers/limits
     * product is fast-follow once real customers need different limits
     * from each other.
     */
    public const PLAN_TIER_FREE = 'free';

    public const PLAN_TIER_PAID = 'paid';

    protected $fillable = [
        'name',
        'subdomain',
        'status',
        'logo_path',
        'favicon_path',
        'certificate_signature_path',
        'certificate_background_path',
        'primary_color',
        'accent_color',
        'custom_domain',
        'plan_tier',
    ];

    protected function casts(): array
    {
        return [
            'custom_domain_verified_at' => 'datetime',
        ];
    }

    public function hasVerifiedCustomDomain(): bool
    {
        return filled($this->custom_domain) && $this->custom_domain_verified_at !== null;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function isPaidTier(): bool
    {
        return $this->plan_tier === self::PLAN_TIER_PAID;
    }

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }
}
