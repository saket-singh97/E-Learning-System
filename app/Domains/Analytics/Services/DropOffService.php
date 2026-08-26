<?php

namespace App\Domains\Analytics\Services;

use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;

/**
 * A3 pt.1 (design doc §2.1, M8) — where students genuinely stop watching,
 * not just "the video ended." Pure query service over VideoWatchEvent, no
 * new schema (design §2.2/§4 decision 2), same posture B0/B2 already
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

        $bucketCounts = $droppedOffPoints
            ->map(fn (int $lastEnd): int => intdiv($lastEnd, RewatchHeatmapService::BUCKET_WIDTH_S) * RewatchHeatmapService::BUCKET_WIDTH_S)
            ->countBy()
            ->sortDesc();

        return (int) $bucketCounts->keys()->first();
    }
}
