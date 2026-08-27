<?php

namespace App\Domains\Analytics\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * S19 N18 pt.1 — a tenant's own badge catalog. Auto-awarded by
 * `BadgeEvaluator` when either `xp_threshold` (a flat XP total) or `criteria`
 * (jsonb, e.g. `{"streak_days": 7}`) is first met — never both required at
 * once, a badge picks one condition (design doc §3.3).
 */
class Badge extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'key',
        'name',
        'description',
        'icon',
        'xp_threshold',
        'criteria',
    ];

    protected function casts(): array
    {
        return [
            'criteria' => 'array',
        ];
    }
}
