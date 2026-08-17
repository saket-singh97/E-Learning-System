<?php

namespace App\Domains\Security\Models;

use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Deliberately not BelongsToTenant — same reasoning `ImpersonationAudit`
 * (S9 B3) already established: tenant_id is nullable (a platform-level
 * action has none), and the trait's creating() hook would throw on every
 * unbound write. Not registered in TenantModelRegistry for the identical
 * reason (that registry assumes BelongsToTenant's own behavior). Append-
 * only — an audit entry is never edited after it's written.
 */
class AuditLogEntry extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'actor_id',
        'action',
        'subject_type',
        'subject_id',
        'metadata',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
