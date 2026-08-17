<?php

namespace App\Domains\Security\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only — a login attempt is a fact recorded once, never edited.
 * `UPDATED_AT = null` matches the migration's own lack of an
 * `updated_at` column.
 */
class LoginHistory extends Model
{
    use BelongsToTenant;

    /**
     * Eloquent's own pluralization guesses `login_histories`; the
     * migration (matching the design doc's own singular table name)
     * created `login_history` — same mismatch class `TenantStorageUsage`
     * (S11 B0) already hit, fixed the identical way.
     */
    protected $table = 'login_history';

    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'ip_address',
        'country',
        'city',
        'device_fingerprint',
        'successful',
        'flagged_impossible_travel',
    ];

    protected function casts(): array
    {
        return [
            'successful' => 'boolean',
            'flagged_impossible_travel' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
