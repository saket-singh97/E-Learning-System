<?php

namespace App\Domains\Content\Services;

use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\BandwidthQuotaExceededException;
use App\Models\User;

/**
 * The single answer to "may this viewer see this lesson, and can it play?".
 *
 * Block 2 built this gate inline in StreamLessonController. Block 3 then added
 * a watch page on a separate route that never re-applied it, so draft and
 * not-yet-released lesson titles were readable by anyone with the URL. Both
 * surfaces now call this class, so the two cannot drift apart again.
 */
class LessonAccess
{
    public function __construct(
        private readonly BandwidthQuota $quota,
        private readonly LessonCompletion $completion,
    ) {}
    /** @var array<int, string> Roles allowed to stream a gated lesson at all. */
    public const STREAM_ROLES = ['student', 'instructor', 'institute_admin'];

    /** @var array<int, string> Roles allowed to preview their own tenant's unfinished work. */
    public const STAFF_ROLES = ['instructor', 'institute_admin'];

    /**
     * Live means the owning course is published and the lesson's drip date has
     * passed. Staff bypass this to review their own drafts before release.
     *
     * Null-safe on the parents: sections and courses soft-delete, and their
     * lessons keep their own rows, so both relations read null while the
     * parent sits in the trash.
     */
    public function isLive(Lesson $lesson): bool
    {
        return $lesson->section?->course?->status === 'published'
            && ! $lesson->isScheduledForFuture();
    }

    /**
     * "Playable" generalized (S9 B6) to mean "this lesson's own content is
     * actually present," per type — video/live_session still need a file
     * that finished uploading (checking video_status alone is not enough: a
     * `failed` upload, or a row whose status was set without a file, leaves
     * video_disk_path null, and the driver would then resolve the bare disk
     * root instead of a file); pdf/text/embed each need their own single
     * column filled. `quiz` (and anything future) falls to the same "not
     * ready" default a video lesson with no file already gets — WatchLessonController
     * never legitimately serves a quiz-type lesson (ShowQuizController does),
     * so this stays a safe 404 rather than a type this method needs to know
     * how to render.
     */
    public function isPlayable(Lesson $lesson): bool
    {
        return match ($lesson->type) {
            'video', 'live_session' => $lesson->video_status === 'ready' && filled($lesson->video_disk_path),
            'pdf' => filled($lesson->pdf_disk_path),
            'text' => filled($lesson->text_body),
            'embed' => filled($lesson->embed_url),
            default => false,
        };
    }

    /**
     * A trashed section or course takes its lessons down with it for everyone,
     * staff included: the parent is in the bin, so the lesson is unreachable
     * until it is restored rather than half-alive behind a stale URL.
     */
    public function isVisibleTo(Lesson $lesson, ?User $viewer): bool
    {
        if ($lesson->section?->course === null) {
            return false;
        }

        return $this->isLive($lesson) || $this->isStaff($viewer);
    }

    /**
     * SESSION_DOMAIN is a shared parent domain (CLAUDE.md), so a session
     * cookie from tenant A's viewer stays valid on tenant B's subdomain, and
     * Spatie roles carry no tenant_id — so the old role-only check let a
     * tenant A student stream any tenant B lesson they could guess/reach the
     * id of, once resolved under tenant B's own host. Free-preview lessons
     * stay an unconditional bypass (marketing content, meant to be visible
     * to anyone including guests, same as before this fix).
     *
     * S13 B6 — sequential unlocking (C8 pt.2): a lesson whose prerequisites
     * (per PrerequisiteGraph) aren't all complete for this viewer (per
     * LessonCompletion) is denied, same as every other gate this method
     * already enforces. Staff bypass it — they're not "completing" lessons
     * in sequence, they're previewing their own content, same posture
     * isVisibleTo()/canJoinLive() already take.
     */
    public function canStream(Lesson $lesson, ?User $viewer): bool
    {
        if ($lesson->is_free_preview) {
            return true;
        }

        if ($viewer === null || $viewer->tenant_id !== $lesson->tenant_id) {
            return false;
        }

        if (! $viewer->hasAnyRole(self::STREAM_ROLES)) {
            return false;
        }

        if ($this->isStaff($viewer)) {
            return true;
        }

        return $lesson->prerequisites->every(
            fn (Lesson $prerequisite) => $this->completion->isComplete($prerequisite, $viewer),
        );
    }

    /**
     * Public (S13 B17) — CourseCatalog reuses this rather than
     * re-deriving "is this viewer staff" with its own inline role check,
     * the exact CLAUDE.md "one place answers this" gap the review pass
     * found: CourseCatalog's own `where('status', 'published')` had
     * silently dropped the staff-preview bypass isCourseVisibleTo()
     * already establishes for the syllabus page.
     */
    public function isStaff(?User $viewer): bool
    {
        return (bool) $viewer?->hasAnyRole(self::STAFF_ROLES);
    }

    /**
     * S9 B12 — closes S2's own "measuring only, no quota" note
     * (BandwidthUsageDaily::record()). Centralized here rather than
     * inline in each controller, matching this class's own reason for
     * existing (a stream-time decision that must not drift between
     * StreamLessonController and DownloadLessonPdfController, both of
     * which record bandwidth). Called with the exact byte count the
     * request is about to serve, not just "is the tenant already over" —
     * a request that would itself push the tenant past the cap is
     * refused before serving it, not only the next one after.
     */
    public function assertWithinBandwidthQuota(Lesson $lesson, int $bytes): void
    {
        $tenant = Tenant::findOrFail($lesson->tenant_id);

        if ($bytes > $this->quota->remainingFor($tenant)) {
            throw BandwidthQuotaExceededException::forTenant($tenant->id);
        }
    }

    /**
     * Course-level counterpart to isVisibleTo() — same staff-bypass posture
     * as isLive(). Found missing in the S5 B8 review: ShowSyllabusController
     * rendered a draft course's title and section titles to any guest who
     * knew/guessed the slug, gating only the lesson rows inside it. Same
     * category of leak isVisibleTo()'s own history warns about, one level up.
     */
    public function isCourseVisibleTo(Course $course, ?User $viewer): bool
    {
        return $course->status === 'published' || $this->isStaff($viewer);
    }

    /**
     * Content -> Batches model read (same precedent as isVisibleTo() reading
     * ->section?->course), reversed: which BatchSession is this viewer's own
     * scheduled occurrence of a live_session lesson. A viewer with no active
     * enrolment in any batch offering this session gets null back — the
     * syllabus tree renders the topic without a join affordance in that
     * case (design doc §2.4 decision 2). This is resolution only, not the
     * join gate itself — canJoinLive() (S5 B3) is the authoritative boolean
     * a join endpoint re-checks before actually handing out a URL.
     */
    public function myLiveBatchSession(Lesson $lesson, ?User $viewer): ?BatchSession
    {
        if ($lesson->type !== 'live_session' || $viewer === null) {
            return null;
        }

        return BatchSession::where('lesson_id', $lesson->id)
            ->whereIn('batch_id', Enrolment::query()
                ->where('user_id', $viewer->id)
                ->where('status', 'active')
                ->pluck('batch_id'))
            // Deterministic tiebreak for a viewer enrolled in more than one
            // batch offering this same lesson: soonest occurrence first, not
            // whichever row the database happens to return without an
            // explicit order.
            ->orderBy('starts_at')
            ->first();
    }

    /**
     * The formal join gate (design doc §3.3, extended) — the one true answer
     * a join endpoint re-checks before ever handing out a joinUrl(), so it
     * cannot drift from whatever a listing UI's own resolution
     * (myLiveBatchSession()) happened to show. Staff bypass batch enrolment
     * entirely (teacher joins as moderator, same posture as isVisibleTo()'s
     * staff bypass); a student needs an active Enrolment in the session's
     * own batch. Also requires liveState() === STATE_LIVE for everyone,
     * including staff — the design doc's own §3.3 code sample omits this,
     * but without it an enrolled viewer could hit the join endpoint directly
     * at any time (before scheduled, or long after the class ended) and
     * still get handed the meeting URL; "join, live" means both.
     */
    public function canJoinLive(Lesson $lesson, BatchSession $session, ?User $viewer): bool
    {
        if ($lesson->type !== 'live_session' || $session->lesson_id !== $lesson->id) {
            return false;
        }

        $course = $lesson->section?->course;

        if ($course === null || ! $this->isCourseVisibleTo($course, $viewer)) {
            return false;
        }

        if ($session->liveState() !== BatchSession::STATE_LIVE) {
            return false;
        }

        if ($this->isStaff($viewer)) {
            return true;
        }

        return Enrolment::query()
            ->where('batch_id', $session->batch_id)
            ->where('user_id', $viewer?->id)
            ->where('status', 'active')
            ->exists();
    }
}
