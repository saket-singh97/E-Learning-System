<?php

namespace App\Domains\Fees\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeCoupon extends Model
{
    use BelongsToTenant;

    public const DISCOUNT_TYPES = ['percentage', 'fixed'];

    public const STATUSES = ['active', 'disabled'];

    protected $fillable = [
        'tenant_id',
        'code',
        'discount_type',
        'discount_value',
        'usage_limit',
        'used_count',
        'expires_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'usage_limit' => 'integer',
            'used_count' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(FeeInvoice::class, 'coupon_id');
    }
}
