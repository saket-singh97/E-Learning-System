<?php

namespace App\Domains\Assessments\Models;

use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\Lesson;
use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Question extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const TYPES = ['mcq', 'true_false', 'short_answer', 'numerical'];

    public const DIFFICULTIES = ['easy', 'medium', 'hard'];

    public const STATUSES = ['draft', 'approved'];

    public const SOURCES = ['manual', 'ai_generated'];

    protected $fillable = [
        'tenant_id',
        'course_id',
        'lesson_id',
        'type',
        'stem',
        'options',
        'correct_answer',
        'explanation',
        'difficulty',
        'concept',
        'status',
        'source',
        'created_by',
        'token_cost',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'correct_answer' => 'array',
            'token_cost' => 'array',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Which topic seeded this question, if any — Assessments -> Content
     * model read, same precedent as BatchSession::lesson() (Batches ->
     * Content) and Batch::course() before it.
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
