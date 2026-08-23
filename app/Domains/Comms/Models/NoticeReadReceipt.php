<?php

namespace App\Domains\Comms\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user "seen" state only, not per-user delivery targeting (design
 * decision 11) — a notice is a broadcast to a role, this row just tracks
 * whether one specific viewer has opened it.
 */
class NoticeReadReceipt extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'notice_id',
        'user_id',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function notice(): BelongsTo
    {
        return $this->belongsTo(Notice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
