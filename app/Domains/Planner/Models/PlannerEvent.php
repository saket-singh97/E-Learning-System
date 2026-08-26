<?php

namespace App\Domains\Planner\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * S19 N12 pt.1 — custom, user-owned calendar events only. Batch sessions
 * and assignment due dates are never copied in here — `PlannerAggregator`
 * reads those straight from their own tables at query time (design doc §4
 * decision 3).
 */
class PlannerEvent extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'title',
        'description',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
