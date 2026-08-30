<?php

namespace App\Domains\Growth\Models;

use App\Domains\Assessments\Models\Quiz;
use App\Domains\Content\Models\Course;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScholarshipTest extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'course_id',
        'title',
        'quiz_id',
        'opens_at',
        'closes_at',
    ];

    protected function casts(): array
    {
        return [
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(ScholarshipTestRegistration::class);
    }
}
