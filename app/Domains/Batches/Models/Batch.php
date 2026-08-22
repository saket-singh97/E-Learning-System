<?php

namespace App\Domains\Batches\Models;

use App\Domains\Content\Models\Course;
use App\Models\User;
use App\Support\Security\SanitizesHtml;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Batch extends Model
{
    use BelongsToTenant, SanitizesHtml, SoftDeletes;

    public const SELF_ENROL_MODES = ['manual_only', 'self_enrol_approval', 'self_enrol_direct'];

    public const STATUSES = ['draft', 'published', 'archived'];

    protected $fillable = [
        'tenant_id',
        'course_id',
        'instructor_id',
        'title',
        'slug',
        'description',
        'starts_on',
        'ends_on',
        'seat_limit',
        'price',
        'is_paid',
        'instalment_plan',
        'self_enrol_mode',
        'status',
    ];

    protected array $sanitizable = [
        'description',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'seat_limit' => 'integer',
            'price' => 'decimal:2',
            'is_paid' => 'boolean',
            'instalment_plan' => 'array',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(BatchSession::class)->orderBy('position');
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(Enrolment::class);
    }
}
