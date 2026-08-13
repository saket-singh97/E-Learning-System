<?php

namespace App\Domains\Batches\Jobs;

use App\Domains\Batches\Events\AttendanceRecorded;
use App\Domains\Content\Models\AttendanceRecord;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Scheduled via Schedule::job() (bootstrap/app.php withSchedule). Real
 * queue retry as of S9 B10 — see ExpirePendingEnrolments's own docblock for
 * why $tries/backoff() were dead configuration on a plain (non-ShouldQueue)
 * class before this. Idempotent: the alreadyPresent exists() check (below)
 * already means re-running this whole job creates no duplicate
 * AttendanceRecord rows — confirmed before adding retry, not assumed.
 *
 * Unlike ExpirePendingEnrolments, this job *creates* new rows rather than
 * only updating existing ones. BelongsToTenant's creating() hook reads
 * CurrentTenant::id() unconditionally, which throws with no tenant bound —
 * so every AttendanceRecord::create() below runs inside withoutEvents(),
 * with tenant_id set explicitly from the lesson it's scored against.
 *
 * CurrentTenant explicitly cleared up front, same reasoning as
 * ExpirePendingEnrolments: a persistent Horizon worker can run any other
 * tenant-bound job immediately before this one in the same PHP process, and
 * a stale binding here would silently scope the VideoWatchEvent sweep query
 * below to just that one leftover tenant instead of every tenant.
 */
class RecordVideoCoverageAttendance implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const COVERAGE_THRESHOLD = 0.8;

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

        VideoWatchEvent::query()
            ->select('lesson_id', 'user_id')
            ->distinct()
            ->get()
            ->each(function (VideoWatchEvent $row): void {
                $this->evaluate((int) $row->lesson_id, (int) $row->user_id);
            });
    }

    protected function evaluate(int $lessonId, int $userId): void
    {
        $lesson = Lesson::find($lessonId);

        if ($lesson === null || blank($lesson->video_duration_seconds) || $lesson->video_duration_seconds <= 0) {
            return;
        }

        $events = VideoWatchEvent::where('lesson_id', $lessonId)
            ->where('user_id', $userId)
            ->get();

        $covered = $this->mergedCoveredSeconds($events);

        if ($covered / $lesson->video_duration_seconds < self::COVERAGE_THRESHOLD) {
            return;
        }

        $alreadyPresent = AttendanceRecord::where('attendable_type', Lesson::class)
            ->where('attendable_id', $lessonId)
            ->where('user_id', $userId)
            ->where('status', 'present')
            ->exists();

        if ($alreadyPresent) {
            return;
        }

        AttendanceRecord::withoutEvents(function () use ($lesson, $userId): void {
            AttendanceRecord::create([
                'tenant_id' => $lesson->tenant_id,
                'attendable_type' => Lesson::class,
                'attendable_id' => $lesson->id,
                'user_id' => $userId,
                'status' => 'present',
                'source' => 'video_coverage',
                'recorded_at' => now(),
            ]);
        });

        event(new AttendanceRecorded($userId, $lesson->tenant_id, 'present'));
    }

    /**
     * No segment-merging helper existed anywhere in the app before this
     * (verified: S2's own resume-position logic — LessonPlayer::mount() —
     * only ever does a plain max(end_s), not a real interval union). Without
     * merging, a viewer who skips around would have overlapping segments
     * double-counted, overstating coverage past the real duration.
     *
     * Public so it's independently unit-testable against hand-built
     * (unpersisted) VideoWatchEvent instances, without a job run + DB
     * round-trip for every boundary case.
     *
     * @param  Collection<int, VideoWatchEvent>  $events
     */
    public function mergedCoveredSeconds(Collection $events): int
    {
        $intervals = $events
            ->map(fn (VideoWatchEvent $event): array => [$event->start_s, $event->end_s])
            ->sort(fn (array $a, array $b): int => $a[0] <=> $b[0])
            ->values();

        $merged = [];

        foreach ($intervals as [$start, $end]) {
            $last = count($merged) - 1;

            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);

                continue;
            }

            $merged[] = [$start, $end];
        }

        return array_sum(array_map(fn (array $interval): int => $interval[1] - $interval[0], $merged));
    }
}
