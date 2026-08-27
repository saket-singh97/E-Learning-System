<?php

namespace App\Domains\Growth\Models;

use App\Domains\Fees\Models\FeePayment;
use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateReferral extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['pending', 'confirmed', 'reversed'];

    protected $fillable = [
        'tenant_id',
        'affiliate_code_id',
        'referred_user_id',
        'fee_payment_id',
        'commission_amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'commission_amount' => 'decimal:2',
        ];
    }

    public function affiliateCode(): BelongsTo
    {
        return $this->belongsTo(AffiliateCode::class);
    }

    public function referredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(FeePayment::class, 'fee_payment_id');
    }
}
