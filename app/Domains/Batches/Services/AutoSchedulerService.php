<?php

namespace App\Domains\Batches\Services;

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\TimetableTemplate;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * S24 U22 pt.1 — proposes conflict-free session slots, persists nothing
 * (design §2.3/§4 decision 5). Cycles through the tenant's own
 * `TimetableTemplate` rows (the only reusable weekly-slot shape this
 * codebase has, same source `BatchSessionsRelationManager`'s own
 * "prefill from template" affordance already reads), advancing a
 * template's own next occurrence by a week each time it's used or found
 * conflicting, until `$sessionCount` conflict-free candidates are found or
 * every template has been tried a bounded number of times.
 *
 * Room-limit constraint: real gap-check, not assumed away. Grepping the
 * whole Batches domain (models + migrations) found no physical-room/
 * location column or model anywhere — `BatchSession`'s own "room" fields
 * (`room_created_at`, `createLiveRoom()`) are the virtual live-class
 * meeting room, an unrelated concept. Every proposed candidate carries a
 * `room` key, always null — an accepted v1 gap (design §2.1, U22's own
 * "room limits" line), not a silently dropped constraint.
 *
 * @return Collection<int, array{teacher: ?int, room: null, day_of_week: int, starts_at: Carbon, duration_minutes: int}>
 */
class AutoSchedulerService
{
    private const MAX_ATTEMPTS_PER_TEMPLATE = 8;

    public function propose(Batch $batch, int $sessionCount): Collection
    {
        $templates = TimetableTemplate::orderBy('id')->get();
        $proposals = collect();

        if ($templates->isEmpty() || $sessionCount < 1) {
            return $proposals;
        }

        $detector = app(TeacherConflictDetector::class);
        $cursors = [];

        foreach ($templates as $template) {
            $cursors[$template->id] = Carbon::now()
                ->next($template->day_of_week)
                ->setTimeFrom(Carbon::parse($template->starts_at));
        }

        $templateIndex = 0;

        while ($proposals->count() < $sessionCount) {
            $template = $templates[$templateIndex % $templates->count()];
            $attemptsForThisTemplate = intdiv($templateIndex, $templates->count());

            if ($attemptsForThisTemplate >= self::MAX_ATTEMPTS_PER_TEMPLATE) {
                // Every template exhausted its attempt budget without
                // filling $sessionCount — return whatever was found rather
                // than looping forever on a teacher with no free slots.
                break;
            }

            $candidateStart = $cursors[$template->id];
            $cursors[$template->id] = $candidateStart->clone()->addWeek();
            $templateIndex++;

            $conflicts = $detector->wouldConflict($batch, $candidateStart, $template->duration_minutes)
                || $this->overlapsAnyProposal($proposals, $candidateStart, $template->duration_minutes);

            if ($conflicts) {
                continue;
            }

            $proposals->push([
                'teacher' => $batch->instructor_id,
                'room' => null,
                'day_of_week' => $template->day_of_week,
                'starts_at' => $candidateStart,
                'duration_minutes' => $template->duration_minutes,
            ]);
        }

        return $proposals;
    }

    /**
     * wouldConflict() only sees persisted BatchSession rows — candidates
     * proposed earlier in this same call aren't saved yet, so they need
     * their own in-memory overlap check to avoid this call proposing two
     * slots that collide with each other.
     */
    private function overlapsAnyProposal(Collection $proposals, Carbon $candidateStart, int $durationMinutes): bool
    {
        $candidateEnd = $candidateStart->clone()->addMinutes($durationMinutes);

        return $proposals->contains(function (array $proposal) use ($candidateStart, $candidateEnd): bool {
            $existingEnd = $proposal['starts_at']->clone()->addMinutes($proposal['duration_minutes']);

            return $candidateStart->lt($existingEnd) && $candidateEnd->gt($proposal['starts_at']);
        });
    }
}
