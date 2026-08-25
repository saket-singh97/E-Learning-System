<?php

namespace App\Domains\Analytics\Services;

use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * A1 (design doc §2.1) — engagement ratio + speed profile, one service since
 * both read the exact same VideoWatchEvent rows in the exact same
 * per-student-per-lesson pass (design decision 1).
 *
 * Speed inference has no dedicated wall-clock column to read: the player
 * (resources/views/livewire/lesson-player.blade.php) heartbeats on a fixed
 * setInterval(10000) cadence, reporting [lastReported, currentTime] as
 * start_s/end_s each time. A single heartbeat's video-time delta divided by
 * that fixed 10s wall-clock window is the implied playback speed for that
 * window — indistinguishable from a manual forward-skip on any one event,
 * which is why sustained_2x requires several such heartbeats, not one.
 */
class EngagementMetricsService
{
    public const HEARTBEAT_INTERVAL_S = 10;

    public const SPEED_2X_THRESHOLD = 1.75;

    public const SUSTAINED_MIN_EVENTS = 3;

    public function ratioFor(User $student, Lesson $lesson): float
    {
        if (blank($lesson->video_duration_seconds) || $lesson->video_duration_seconds <= 0) {
            return 0.0;
        }

        $events = VideoWatchEvent::where('lesson_id', $lesson->id)
            ->where('user_id', $student->id)
            ->get();

        $covered = $this->mergedCoveredSeconds($events);

        return min(1.0, $covered / $lesson->video_duration_seconds);
    }

    /**
     * @return array{sustained_2x: bool, fast_heartbeat_count: int, total_heartbeats: int}
     */
    public function speedProfileFor(User $student, Lesson $lesson): array
    {
        $events = VideoWatchEvent::where('lesson_id', $lesson->id)
            ->where('user_id', $student->id)
            ->get();

        $fastCount = $events
            ->filter(fn (VideoWatchEvent $event): bool => $this->impliedSpeed($event) >= self::SPEED_2X_THRESHOLD)
            ->count();

        return [
            'sustained_2x' => $fastCount >= self::SUSTAINED_MIN_EVENTS,
            'fast_heartbeat_count' => $fastCount,
            'total_heartbeats' => $events->count(),
        ];
    }

    protected function impliedSpeed(VideoWatchEvent $event): float
    {
        return ($event->end_s - $event->start_s) / self::HEARTBEAT_INTERVAL_S;
    }

    /**
     * Same interval-merge shape as RecordVideoCoverageAttendance's own
     * mergedCoveredSeconds (S5 B7) — duplicated rather than called cross-
     * domain since that method lives on a Batches Job class, not a
     * reusable service, and the merge itself is a ~15-line utility.
     *
     * @param  Collection<int, VideoWatchEvent>  $events
     */
    protected function mergedCoveredSeconds(Collection $events): int
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
