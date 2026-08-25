<?php

namespace App\Domains\Assessments\Models;

use App\Domains\Content\Models\Lesson;
use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Quiz extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['draft', 'published'];

    protected $fillable = [
        'tenant_id',
        'lesson_id',
        'title',
        'blueprint',
        'time_limit_minutes',
        'max_attempts',
        'status',
        'proctoring_enabled',
        'test_series_id',
    ];

    protected function casts(): array
    {
        return [
            'blueprint' => 'array',
            'time_limit_minutes' => 'integer',
            'max_attempts' => 'integer',
            'proctoring_enabled' => 'boolean',
        ];
    }

    /**
     * The quiz-type Lesson this quiz is anchored to — one topic node in the
     * syllabus tree (design doc §2.4 decision 2, same topic-anchor shape
     * S5 chose for BatchSession::lesson()).
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    public function testSeries(): BelongsTo
    {
        return $this->belongsTo(TestSeries::class);
    }

    public function omrTemplate(): HasOne
    {
        return $this->hasOne(OmrTemplate::class);
    }

    /**
     * The one answer to "may this viewer open/attempt this quiz" — reused by
     * both ShowQuizController's page gate and QuizAttemptService::start()'s
     * own re-check, so the two cannot drift apart (same lesson CLAUDE.md's
     * LessonAccess history warns about). Deliberately reads Content's
     * Lesson/Course models directly rather than calling
     * Content\Services\LessonAccess — cross-domain *model* reads are
     * established precedent (Question/Quiz already belongsTo Lesson);
     * cross-domain *service* calls are not (CLAUDE.md: domains talk through
     * Events only). Staff bypass matches LessonAccess::isVisibleTo()'s own
     * posture — instructors can preview their own tenant's unpublished quiz
     * the same way they can preview an unreleased lesson.
     */
    public function isVisibleTo(?User $viewer): bool
    {
        // SESSION_DOMAIN is a shared parent domain (CLAUDE.md), so a session
        // cookie from tenant A's viewer stays valid on tenant B's subdomain,
        // and Spatie roles carry no tenant_id — so an authenticated viewer
        // from a different tenant than this quiz's own would otherwise pass
        // every check below, staff bypass included. A guest ($viewer null)
        // still reaches the published-course checks past this guard, same
        // "existence is not secret" posture as before.
        if ($viewer !== null && $viewer->tenant_id !== $this->tenant_id) {
            return false;
        }

        if ($viewer?->hasAnyRole(['instructor', 'institute_admin'])) {
            return true;
        }

        if ($this->status !== 'published') {
            return false;
        }

        $course = $this->lesson?->section?->course;

        if ($course === null || $course->status !== 'published') {
            return false;
        }

        return ! $this->lesson->isScheduledForFuture();
    }
}
