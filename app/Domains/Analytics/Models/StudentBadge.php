<?php

namespace App\Domains\Analytics\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * S19 N18 pt.1 — awarded-once join row; the unique (user_id, badge_id) index
 * is what actually enforces "a badge is awarded once", `BadgeEvaluator` only
 * needs to avoid re-checking already-awarded badges, not re-derive that
 * guarantee itself.
 */
class StudentBadge extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'badge_id',
        'awarded_at',
    ];

    protected function casts(): array
    {
        return [
            'awarded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function badge(): BelongsTo
    {
        return $this->belongsTo(Badge::class);
    }
}
