<?php

namespace App\Domains\Fees\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeRefund extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['pending', 'approved', 'rejected', 'processed'];

    protected $fillable = [
        'tenant_id',
        'fee_payment_id',
        'requested_by_user_id',
        'amount',
        'reason',
        'status',
        'gateway_refund_id',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'processed_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(FeePayment::class, 'fee_payment_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
