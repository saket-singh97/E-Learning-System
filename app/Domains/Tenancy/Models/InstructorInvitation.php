<?php

namespace App\Domains\Tenancy\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstructorInvitation extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['pending', 'accepted', 'expired', 'revoked'];

    protected $fillable = [
        'tenant_id',
        'email',
        'batch_ids',
        'token',
        'invited_by_user_id',
        'status',
        'expires_at',
        'accepted_user_id',
    ];

    protected function casts(): array
    {
        return [
            'batch_ids' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function acceptedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
