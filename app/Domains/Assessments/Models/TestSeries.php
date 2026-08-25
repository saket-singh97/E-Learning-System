<?php

namespace App\Domains\Assessments\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * S16 B12 (I10 pt.1) — bundles `Quiz` rows via a nullable FK on the
 * bundled thing (`quizzes.test_series_id`), same shape `Program` (S13)
 * established for bundling `Batch` rows — not a pivot table, since one
 * quiz belongs to at most one series (design doc §4 decision 7).
 */
class TestSeries extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'title',
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

    public function quizzes(): HasMany
    {
        return $this->hasMany(Quiz::class);
    }
}
