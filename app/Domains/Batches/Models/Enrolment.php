<?php

namespace App\Domains\Batches\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Enrolment extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const STATUSES = ['pending_payment', 'pending_approval', 'active', 'waitlisted', 'rejected', 'expired', 'cancelled'];

    public const SOURCES = ['manual_add', 'self_enrol_approval', 'self_enrol_direct'];

    protected $fillable = [
        'tenant_id',
        'batch_id',
        'user_id',
        'status',
        'source',
        'requested_at',
        'decided_at',
        'decided_by',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
