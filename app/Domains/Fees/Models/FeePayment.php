<?php

namespace App\Domains\Fees\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * S12 B13 (design doc §3.2, V3) — SoftDeletes added: a real payment
 * record hard-deleted by a mis-click had no recovery path before this
 * block. No existing `booted()`/model-event hooks on this class to
 * collide with (confirmed by reading the file before this change).
 */
class FeePayment extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const STATUSES = ['created', 'captured', 'failed', 'refunded'];

    protected $fillable = [
        'tenant_id',
        'fee_invoice_id',
        'provider',
        'provider_order_id',
        'provider_payment_id',
        'idempotency_key',
        'amount',
        'status',
        'failure_reason',
        'webhook_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'webhook_verified_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FeeInvoice::class, 'fee_invoice_id');
    }
}
