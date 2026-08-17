<?php

namespace App\Domains\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Deliberately not BelongsToTenant — a plan belongs to the platform, not to
 * any one tenant, and only the Super panel CRUDs it (design doc §2.4
 * decision 2). Every other new S11 model (TenantSubscription,
 * SubscriptionInvoice, TenantStorageUsage) is tenant-scoped; this one is
 * the deliberate exception.
 */
class Plan extends Model
{
    public const CODE_FREE = 'free';

    public const CODE_STARTER = 'starter';

    public const CODE_GROWTH = 'growth';

    public const CODE_ENTERPRISE = 'enterprise';

    protected $fillable = [
        'code',
        'name',
        'seat_limit',
        'storage_limit_gb',
        'bandwidth_limit_gb_per_day',
        'ai_spend_cap_usd_per_day',
        'price_inr_per_month',
        'razorpay_plan_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'seat_limit' => 'integer',
            'storage_limit_gb' => 'integer',
            'bandwidth_limit_gb_per_day' => 'integer',
            'ai_spend_cap_usd_per_day' => 'decimal:2',
            'price_inr_per_month' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class);
    }
}
