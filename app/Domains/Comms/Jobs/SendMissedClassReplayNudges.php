<?php

namespace App\Domains\Comms\Jobs;

use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Batches\Models\LiveRecording;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Models\User;
use App\Notifications\MissedClassReplayNudgeDue;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * M15's "missed-class-replay" nudge (S18 B6, design doc A4 pt.1) — the
 * other half of SendInactivityNudges's own established shape (design doc
 * §4 decision 4): derive a trigger signal, dedupe via the notifications
 * table, once-per-week cadence. Trigger here is a BatchSession whose
 * LiveRecording is ready with no matching VideoWatchEvent, for that
 * session's own lesson, since the recording went ready (LiveRecording has
 * no dedicated "went live" column — its own updated_at is the ready
 * transition, since nothing else ever updates a `ready` row again).
 *
 * Fires per BatchSession, not per lesson (design doc §4 decision 7) — a
 * live_session lesson can recur across many batches/sessions, each with
 * its own recording, so the session is the concrete "this class happened,
 * here is its recording" unit to nudge against.
 *
 * CurrentTenant cleared up front, same unscoped cross-tenant sweep shape
 * every S9 B10-converted job already uses.
 */
class SendMissedClassReplayNudges implements ShouldQueue
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

        LiveRecording::where('status', 'ready')
            ->chunk(200, function (Collection $recordings): void {
                foreach ($recordings as $recording) {
                    $this->processRecording($recording);
                }
            });
    }

    protected function processRecording(LiveRecording $recording): void
    {
        $session = $recording->batchSession;

        if ($session === null || $session->lesson_id === null) {
            return;
        }

        foreach ($this->activelyEnrolledStudents($session) as $student) {
            $this->maybeNudge($student, $session, $recording);
        }
    }

    /**
     * @return Collection<int, User>
     */
    protected function activelyEnrolledStudents(BatchSession $session): Collection
    {
        return User::role('student')
            ->whereIn('id', Enrolment::where('batch_id', $session->batch_id)
                ->where('status', 'active')
                ->pluck('user_id'))
            ->get();
    }

    protected function maybeNudge(User $student, BatchSession $session, LiveRecording $recording): void
    {
        if ($this->hasWatchedReplay($student, $session, $recording)) {
            return;
        }

        if ($this->alreadyNudgedThisWeek($student)) {
            return;
        }

        $student->notify(new MissedClassReplayNudgeDue($session));
    }

    protected function hasWatchedReplay(User $student, BatchSession $session, LiveRecording $recording): bool
    {
        return VideoWatchEvent::where('user_id', $student->id)
            ->where('lesson_id', $session->lesson_id)
            ->where('created_at', '>=', $recording->updated_at)
            ->exists();
    }

    protected function alreadyNudgedThisWeek(User $student): bool
    {
        return $student->notifications()
            ->where('type', MissedClassReplayNudgeDue::class)
            ->where('created_at', '>=', now()->startOfWeek())
            ->exists();
    }
}
