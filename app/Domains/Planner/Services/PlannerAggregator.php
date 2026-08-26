<?php

namespace App\Domains\Planner\Services;

use App\Domains\Assignments\Models\Assignment;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Planner\Models\PlannerEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * S19 N12 pt.1 — computed at read time, no materialized aggregate table:
 * merges the viewer's own `PlannerEvent` rows with `BatchSession`s for
 * batches they're actively enrolled in and `Assignment.due_at` for those
 * same batches' own courses. Design doc §4 decision 3's own third source —
 * "quiz open/close windows" — doesn't exist: verified before writing this
 * service, `Quiz` has no `opens_at`/`closes_at` (or any scheduling column)
 * anywhere in this app, only `time_limit_minutes` (a duration once
 * started) and `max_attempts`; `Quiz::isVisibleTo()` gates on the parent
 * lesson's own access rules, not a date window. Two real sources, not
 * three — same "verify before building" discipline every prior slice's own
 * design doc already applied when a roadmap assumption turned out stale.
 */
class PlannerAggregator
{
    /**
     * @return Collection<int, array{source: string, id: int, title: string, starts_at: Carbon, ends_at: ?Carbon}>
     */
    public function eventsFor(User $viewer, Carbon $from, Carbon $to): Collection
    {
        $custom = PlannerEvent::where('user_id', $viewer->id)
            ->whereBetween('starts_at', [$from, $to])
            ->get()
            ->map(fn (PlannerEvent $event): array => [
                'source' => 'custom',
                'id' => $event->id,
                'title' => $event->title,
                'starts_at' => $event->starts_at,
                'ends_at' => $event->ends_at,
            ]);

        $batchIds = Enrolment::where('user_id', $viewer->id)
            ->where('status', 'active')
            ->pluck('batch_id');

        $sessions = BatchSession::whereIn('batch_id', $batchIds)
            ->whereBetween('starts_at', [$from, $to])
            ->with('batch')
            ->get()
            ->map(fn (BatchSession $session): array => [
                'source' => 'batch_session',
                'id' => $session->id,
                'title' => $session->batch?->title ?? 'Batch session',
                'starts_at' => $session->starts_at,
                'ends_at' => $session->starts_at->clone()->addMinutes($session->duration_minutes),
            ]);

        $courseIds = Batch::whereIn('id', $batchIds)->pluck('course_id');

        $assignments = Assignment::whereHas('lesson.section', fn ($query) => $query->whereIn('course_id', $courseIds))
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [$from, $to])
            ->get()
            ->map(fn (Assignment $assignment): array => [
                'source' => 'assignment_due',
                'id' => $assignment->id,
                'title' => $assignment->title,
                'starts_at' => $assignment->due_at,
                'ends_at' => null,
            ]);

        return $custom->concat($sessions)->concat($assignments)
            ->sortBy('starts_at')
            ->values();
    }
}
