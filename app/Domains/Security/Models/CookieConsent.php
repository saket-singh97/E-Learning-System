<?php

namespace App\Domains\Security\Models;

use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Deliberately not BelongsToTenant — a consent can be recorded before any
 * tenant resolves (the bare login page) and before any user exists
 * (design doc §4 decision 9), so the trait's creating() hook would throw
 * on the exact case this model exists to cover.
 */
class CookieConsent extends Model
{
    /**
     * No created_at/updated_at columns at all — consented_at is this
     * row's own single timestamp, not Eloquent's pair.
     */
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'session_id',
        'categories',
        'consented_at',
    ];

    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'consented_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
