<?php

namespace App\Domains\Assignments\Models;

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Lesson;
use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Assignment extends Model
{
    use BelongsToTenant;

    /**
     * Same allow-list posture as Lesson::EMBEDDABLE_HOSTS (S9 B6) —
     * allowed_file_types is validated against this fixed list at save
     * time, not left as free-form text a submission upload would then have
     * to trust blindly.
     */
    public const FILE_TYPE_OPTIONS = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'ppt', 'pptx', 'xls', 'xlsx', 'zip', 'txt'];

    protected $fillable = [
        'tenant_id',
        'lesson_id',
        'title',
        'instructions',
        'due_at',
        'max_marks',
        'allowed_file_types',
        'max_file_size_kb',
        'rubric',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'max_marks' => 'decimal:2',
            'allowed_file_types' => 'array',
            'max_file_size_kb' => 'integer',
            'rubric' => 'array',
        ];
    }

    /**
     * The assignment-type Lesson this assignment is anchored to — same
     * topic-anchor shape S5 (BatchSession::lesson()) and S6 (Quiz::lesson())
     * both established.
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }

    /**
     * Deliberately stricter than Quiz::isVisibleTo() (S6 B5): a guest or a
     * student with no active enrolment in a batch offering this course
     * cannot open/submit an assignment at all, not just "existence isn't
     * secret" — a real file upload tied to a due date and grading history
     * warrants requiring genuine enrolment, unlike a quiz attempt. Staff
     * bypass mirrors every other isVisibleTo() in this app (preview their
     * own tenant's unpublished/unreleased work). Reads Batches\Enrolment/
     * Batch directly — cross-domain *model* reads are established
     * precedent (Content\LessonAccess already reads Batches\Enrolment for
     * the identical reason, design doc §2.4 decision "reuse canJoinLive()'s
     * enrolment check, rebuilt locally"); calling into a Batches *service*
     * would not be.
     */
    public function isVisibleTo(?User $viewer): bool
    {
        if ($viewer !== null && $viewer->tenant_id !== $this->tenant_id) {
            return false;
        }

        if ($viewer?->hasAnyRole(['instructor', 'institute_admin'])) {
            return true;
        }

        $course = $this->lesson?->section?->course;

        if ($course === null || $course->status !== 'published') {
            return false;
        }

        if ($this->lesson->isScheduledForFuture()) {
            return false;
        }

        if ($viewer === null) {
            return false;
        }

        return Enrolment::query()
            ->where('user_id', $viewer->id)
            ->where('status', 'active')
            ->whereIn('batch_id', Batch::where('course_id', $course->id)->pluck('id'))
            ->exists();
    }

    /**
     * A Filament Repeater's per-item default() only fires when an item is
     * added through the UI's own "add" action (it mounts the child schema
     * fresh) — a form fill that sets repeater state directly, real save
     * included, never triggers it, so a Hidden 'id' field with a default()
     * closure silently never ran. Backfilling here instead guarantees every
     * rubric row gets a stable id no matter which write path created it,
     * needed since AssignmentSubmission.rubric_scores (B4) keys off it.
     */
    protected static function booted(): void
    {
        static::saving(function (self $assignment): void {
            if (is_array($assignment->rubric)) {
                $assignment->rubric = collect($assignment->rubric)
                    ->map(fn (array $criterion): array => $criterion + ['id' => (string) Str::uuid()])
                    ->all();
            }
        });
    }
}
