<?php

namespace App\Domains\Assignments\Jobs;

use App\Domains\Assignments\Models\Certificate;
use App\Domains\Assignments\Services\CertificateService;
use App\Domains\Assignments\Services\GradebookService;
use App\Domains\Batches\Models\Enrolment;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Design doc §3.3 — daily, unscoped, same shape S9 B10 established for
 * this exact class of sweep (ExpirePendingEnrolments/
 * RecordVideoCoverageAttendance): CurrentTenant explicitly cleared up
 * front, since a persistent Horizon worker can run any other tenant-bound
 * job immediately before this one in the same PHP process, and this sweep
 * relies on BelongsToTenant's global scope being a genuine no-op across
 * every tenant's rows.
 *
 * Deliberately a daily sweep, not a real-time hook off quiz submission or
 * assignment grading (design doc §2.4 decision 6) — those two write paths
 * live in two different domains (Assessments, this one), and wiring a
 * live completion check into both would mean either a new cross-domain
 * event fired from Assessments (a domain this slice otherwise never
 * touches) or an inline call from here into an Assessments service
 * (forbidden — CLAUDE.md, domains talk through Events only).
 */
class CheckCourseCompletionAndIssueCertificates implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        app(CurrentTenant::class)->clear();

        $gradebook = app(GradebookService::class);
        $certificates = app(CertificateService::class);

        Enrolment::where('status', 'active')
            // S10 B15 review pass: Batch/Course are both SoftDeletes, and
            // BatchResource's own DeleteAction has no guard against active
            // enrolments — an active row can point at a soft-deleted batch
            // (or a batch whose own course was soft-deleted), and this
            // sweep would otherwise dereference a null ->batch->course
            // every single day for that one stale enrolment. Filtered out
            // at the query level, same fix applied to BuildsGradebookRows
            // and both Gradebook Filament pages.
            ->whereHas('batch', fn ($query) => $query->whereHas('course'))
            ->with(['user', 'batch.course'])
            ->get()
            ->each(function (Enrolment $enrolment) use ($gradebook, $certificates): void {
                $student = $enrolment->user;
                $course = $enrolment->batch->course;

                // Check-before-insert is the cheap steady-state guard (this
                // runs daily against the same students, most of whom either
                // already have a certificate or aren't complete yet); the
                // certificates.(tenant_id, user_id, course_id) unique index
                // is what actually prevents a race, same belt-and-suspenders
                // posture as every other seat/slot guard in this app.
                $alreadyIssued = Certificate::where('tenant_id', $enrolment->tenant_id)
                    ->where('user_id', $student->id)
                    ->where('course_id', $course->id)
                    ->exists();

                if ($alreadyIssued || ! $gradebook->isComplete($student, $course)) {
                    return;
                }

                $certificates->issue($student, $course, $gradebook->scoreFor($student, $course)['weighted_percent']);
            });
    }
}
