<?php

namespace App\Domains\Tenancy\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Deliberately does NOT use BelongsToTenant, unlike every other model in
 * TenantModelRegistry — see this migration's own docblock. tenant_id is
 * set explicitly by ImpersonationService from the target user's own tenant,
 * not resolved from CurrentTenant.
 */
class ImpersonationAudit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'actor_id',
        'target_user_id',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
