<?php

namespace App\Domains\Analytics\Services;

use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;
use Illuminate\Support\Collection;

/**
 * A3 (design doc §2.1, M8) — where students genuinely stop watching, not
 * just "the video ended." Pure query service over VideoWatchEvent, no new
 * schema (design §2.2/§4 decision 2), same posture B0/B2 already
 * established.
 */
class DropOffService
{
    /**
     * A student whose own last watch event ends within this many seconds
     * of the lesson's own total duration counts as having finished, not
     * dropped off — floor(currentTime) heartbeats rarely land exactly on
     * the final second.
     */
    public const NEAR_END_TOLERANCE_S = 5;

    /**
     * The video-time second where the largest share of students' own last
     * watch event ends, bucketed to RewatchHeatmapService's own 10s width
     * (same domain, same bucket convention) — null when no student's last
     * event falls short of the lesson's own duration (everyone finished).
     */
    public function dropOffPointFor(Lesson $lesson): ?int
    {
        $buckets = $this->bucketedDropOffCounts($lesson);

        return $buckets === null ? null : (int) $buckets->keys()->first();
    }

    /**
     * A3 pt.2 (S18 B5) — same bucketing as dropOffPointFor(), plus the
     * runtime percentage and affected-student count the instructor-facing
     * widget needs, without a second duplicate query pass.
     *
     * @return array{point_s: int, percentage_of_runtime: int, students_affected: int}|null
     */
    public function detailsFor(Lesson $lesson): ?array
    {
        $buckets = $this->bucketedDropOffCounts($lesson);

        if ($buckets === null) {
            return null;
        }

        $point = (int) $buckets->keys()->first();

        return [
            'point_s' => $point,
            'percentage_of_runtime' => (int) round($point / $lesson->video_duration_seconds * 100),
            'students_affected' => (int) $buckets->first(),
        ];
    }

    /**
     * @return Collection<int, int>|null bucket start second => student count, sorted desc; null when not computable.
     */
    protected function bucketedDropOffCounts(Lesson $lesson): ?Collection
    {
        if (blank($lesson->video_duration_seconds) || $lesson->video_duration_seconds <= 0) {
            return null;
        }

        $lastEndPerStudent = VideoWatchEvent::where('lesson_id', $lesson->id)
            ->get()
            ->groupBy('user_id')
            ->map(fn ($events): int => (int) $events->max('end_s'));

        $droppedOffPoints = $lastEndPerStudent->filter(
            fn (int $lastEnd): bool => $lastEnd < $lesson->video_duration_seconds - self::NEAR_END_TOLERANCE_S,
        );

        if ($droppedOffPoints->isEmpty()) {
            return null;
        }

        return $droppedOffPoints
            ->map(fn (int $lastEnd): int => intdiv($lastEnd, RewatchHeatmapService::BUCKET_WIDTH_S) * RewatchHeatmapService::BUCKET_WIDTH_S)
            ->countBy()
            ->sortDesc();
    }
}
