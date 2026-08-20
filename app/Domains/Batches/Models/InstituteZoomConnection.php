<?php

namespace App\Domains\Batches\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per tenant (design doc decision 2) — an institute admin connects
 * their own Zoom account once (ZoomOAuthController); every meeting
 * ZoomMeetingsProvider (B7) creates for that tenant's batch sessions
 * authenticates as this connection, refreshing the token when it's past
 * token_expires_at (B11). access_token/refresh_token use Laravel's own
 * `encrypted` cast — no prior precedent for stored OAuth tokens in this
 * app, but the obvious minimum bar for a secret sitting in the database.
 */
class InstituteZoomConnection extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'account_id',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'connected_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
        ];
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }
}
