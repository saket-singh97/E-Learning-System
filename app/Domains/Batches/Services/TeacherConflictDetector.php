<?php

namespace App\Domains\Batches\Services;

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Write-time check, not a background sweep (design doc decision 4) — same
 * "refuse before persisting" posture PrerequisiteGraph's own cycle check
 * (S13) already established for a different bounded check. Fetches every
 * session sharing the batch's own instructor and compares windows in PHP
 * rather than an inline raw-SQL overlap expression, since this app's own
 * security baseline forbids that inside app/Domains — the same
 * bounded-not-optimized posture PrerequisiteGraph's own DFS already takes.
 */
class TeacherConflictDetector
{
    public function wouldConflict(
        Batch $batch,
        DateTimeInterface|string $startsAt,
        int $durationMinutes,
        ?int $ignoringSessionId = null,
    ): bool {
        if ($batch->instructor_id === null) {
            return false;
        }

        $startsAt = Carbon::parse($startsAt);
        $endsAt = $startsAt->clone()->addMinutes($durationMinutes);

        return BatchSession::whereHas(
            'batch',
            fn ($query) => $query->where('instructor_id', $batch->instructor_id),
        )
            ->when(
                $ignoringSessionId !== null,
                fn ($query) => $query->where('id', '!=', $ignoringSessionId),
            )
            ->get()
            ->contains(function (BatchSession $existing) use ($startsAt, $endsAt): bool {
                /** @var CarbonInterface $existingEnd */
                $existingEnd = $existing->starts_at->clone()->addMinutes($existing->duration_minutes);

                return $startsAt->lt($existingEnd) && $endsAt->gt($existing->starts_at);
            });
    }
}
