<?php

namespace App\Domains\Analytics\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * S19 N18 pt.1 — one row per student, updated in place by
 * `GamificationService` on every qualifying domain event. `last_activity_date`
 * drives the streak (design doc §3.3); `level` is derived from `xp_total`
 * (`GamificationService::LEVEL_XP_STEP`), never set directly.
 */
class GamificationProfile extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'xp_total',
        'level',
        'current_streak_days',
        'longest_streak_days',
        'last_activity_date',
    ];

    protected function casts(): array
    {
        return [
            'last_activity_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
