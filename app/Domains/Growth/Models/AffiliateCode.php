<?php

namespace App\Domains\Growth\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * S20 R1 — Growth's own domain, not Fees'/Billing's: an affiliate's
 * commission is money flowing to a third party who is neither the paying
 * student nor the platform (design doc §3.1).
 */
class AffiliateCode extends Model
{
    use BelongsToTenant;

    public const COMMISSION_TYPES = ['percentage', 'fixed'];

    public const STATUSES = ['active', 'disabled'];

    protected $fillable = [
        'tenant_id',
        'owner_user_id',
        'code',
        'commission_type',
        'commission_value',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'commission_value' => 'decimal:2',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(AffiliateReferral::class);
    }

    public function payoutRequests(): HasMany
    {
        return $this->hasMany(AffiliatePayoutRequest::class);
    }
}
