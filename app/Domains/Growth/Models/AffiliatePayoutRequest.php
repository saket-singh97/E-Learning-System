<?php

namespace App\Domains\Growth\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliatePayoutRequest extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['requested', 'approved', 'paid', 'rejected'];

    protected $fillable = [
        'tenant_id',
        'affiliate_code_id',
        'amount',
        'status',
        'requested_at',
        'decided_at',
        'decided_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function affiliateCode(): BelongsTo
    {
        return $this->belongsTo(AffiliateCode::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
