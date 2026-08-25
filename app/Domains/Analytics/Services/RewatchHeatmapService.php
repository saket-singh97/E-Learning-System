<?php

namespace App\Domains\Analytics\Services;

use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;
use Illuminate\Support\Collection;

/**
 * A2 pt.1 (design doc §2.1, M7) — which seconds of a lesson the batch
 * replays most. Pure query service over VideoWatchEvent, no new schema
 * (design §2.2/§4 decision 2), same posture EngagementMetricsService (B0)
 * already established.
 */
class RewatchHeatmapService
{
    public const BUCKET_WIDTH_S = 10;

    /**
     * Buckets every VideoWatchEvent row for the lesson into fixed-width
     * time windows and counts distinct students whose own [start_s, end_s]
     * overlaps each bucket — a student rewatching the same window several
     * times still counts once, so this reads as "how many students touch
     * this window," not "how many replay events landed here."
     *
     * @return Collection<int, array{bucket_start_s: int, bucket_end_s: int, replay_count: int}>
     */
    public function heatmapFor(Lesson $lesson): Collection
    {
        $events = VideoWatchEvent::where('lesson_id', $lesson->id)->get();

        if ($events->isEmpty()) {
            return collect();
        }

        $bucketStudents = [];

        foreach ($events as $event) {
            $firstBucket = intdiv($event->start_s, self::BUCKET_WIDTH_S);
            $lastBucket = intdiv(max($event->start_s, $event->end_s - 1), self::BUCKET_WIDTH_S);

            for ($bucket = $firstBucket; $bucket <= $lastBucket; $bucket++) {
                $bucketStudents[$bucket][$event->user_id] = true;
            }
        }

        return collect($bucketStudents)
            ->map(fn (array $students, int $bucket): array => [
                'bucket_start_s' => $bucket * self::BUCKET_WIDTH_S,
                'bucket_end_s' => ($bucket + 1) * self::BUCKET_WIDTH_S,
                'replay_count' => count($students),
            ])
            ->sortByDesc('replay_count')
            ->values();
    }
}
