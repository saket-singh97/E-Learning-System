<?php

namespace App\Domains\Batches\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A reusable weekly slot shape (design doc §3.2, decision 3) — a stamp,
 * not a live link: creating a BatchSession "from" one copies these three
 * columns into the session's own row at creation time, no FK persists.
 */
class TimetableTemplate extends Model
{
    use BelongsToTenant;

    public const DAYS_OF_WEEK = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    protected $fillable = [
        'tenant_id',
        'title',
        'day_of_week',
        'starts_at',
        'duration_minutes',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'duration_minutes' => 'integer',
        ];
    }
}
