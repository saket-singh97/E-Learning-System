<?php

namespace App\Domains\Tenancy\Models;

use App\Domains\Billing\Models\Plan;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    // Matches the DB column default — Eloquent doesn't re-fetch a
    // server-side default into memory after create(), so without this a
    // freshly-created (not re-queried) instance reads this attribute as
    // null, not true.
    protected $attributes = [
        'gamification_leaderboard_enabled' => true,
    ];

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
        'plan_id',
        'at_risk_threshold_percent',
        'gamification_leaderboard_enabled',
        'refund_window_days',
        'fee_grace_period_days',
    ];

    protected function casts(): array
    {
        return [
            'custom_domain_verified_at' => 'datetime',
            'at_risk_threshold_percent' => 'float',
            'gamification_leaderboard_enabled' => 'boolean',
            'refund_window_days' => 'integer',
            'fee_grace_period_days' => 'integer',
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

    /**
     * Null until a Super admin actually assigns one (S11 B6+) — every
     * quota/limit read has to branch on plan_id being null and fall back
     * to plan_tier's own legacy caps (TenantPlanLimits owns that branch),
     * never assume this relation resolves.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }
}
