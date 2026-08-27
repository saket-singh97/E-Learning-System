<?php

namespace App\Domains\Tenancy\Models;

use App\Domains\Billing\Models\Plan;
use App\Domains\Fees\Models\FeeInvoice;
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
        'default_currency' => 'INR',
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
        'default_currency',
        'tax_rate',
        'tax_label',
    ];

    protected function casts(): array
    {
        return [
            'custom_domain_verified_at' => 'datetime',
            'at_risk_threshold_percent' => 'float',
            'gamification_leaderboard_enabled' => 'boolean',
            'refund_window_days' => 'integer',
            'fee_grace_period_days' => 'integer',
            'tax_rate' => 'float',
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
     * S20 B14 (review pass) — several new admin screens (refund/payout/
     * membership-plan resources) display an amount that has no currency
     * column of its own; they're always this tenant's own
     * `default_currency`. Reuses `FeeInvoice::CURRENCY_SYMBOLS` rather than
     * a second map, so the two stay in sync by construction.
     */
    public function currencySymbol(): string
    {
        return FeeInvoice::CURRENCY_SYMBOLS[$this->default_currency] ?? "{$this->default_currency} ";
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
