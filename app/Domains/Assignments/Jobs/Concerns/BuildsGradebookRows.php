<?php

namespace App\Domains\Assignments\Jobs\Concerns;

use App\Domains\Assignments\Services\GradebookService;
use App\Domains\Batches\Models\Enrolment;
use Illuminate\Support\Collection;

/**
 * Shared row source for all three gradebook export formats (CSV/Excel/PDF,
 * B9-B10) — plan doc's own instruction: "one underlying data query, not
 * three divergent queries." Extracted here rather than left duplicated a
 * third time once the Excel/PDF jobs needed the identical rows
 * ExportGradebookCsv (B9) already built — same "third copy is the bug
 * class waiting to be forgotten" reasoning as EscapesCsvFormulas/
 * GradebookService::standingFor(). B9's own job was retrofitted onto this
 * trait too, not left as a fourth divergent copy.
 *
 * Not chunked (unlike ExportEngagementRegister's own 200-row chunks) —
 * Excel's FromCollection and the PDF's Blade view both need the whole
 * dataset in memory regardless, so chunking only the CSV half would still
 * leave two of three formats unchunked; at this app's actual scale (one
 * institute's active enrolments) a single ->get() is not the memory risk
 * a tenant-spanning sweep would be.
 */
trait BuildsGradebookRows
{
    /**
     * @return Collection<int, array{name: string, email: string, course: string, quiz_percent: ?float, assignment_percent: ?float, weighted_percent: float, standing: string}>
     */
    protected function gradebookRows(int $tenantId): Collection
    {
        $gradebook = app(GradebookService::class);

        return Enrolment::where('tenant_id', $tenantId)
            ->where('status', 'active')
            // S10 B15 review pass: Batch (and Course) are both SoftDeletes,
            // and BatchResource's own DeleteAction has no guard against
            // active enrolments (checked — unlike AssignmentResource/
            // QuizResource, which both hide delete once attempts/
            // submissions exist). A soft-deleted batch (or a batch whose
            // own course was soft-deleted) left an active Enrolment
            // pointing at a null relation, and every consumer below
            // dereferenced it unconditionally — found reading this trait
            // fresh, not by a failing test. Filtered out at the query
            // level rather than null-checked per row.
            ->whereHas('batch', fn ($query) => $query->whereHas('course'))
            ->with(['user', 'batch.course'])
            ->orderBy('id')
            ->get()
            ->map(function (Enrolment $enrolment) use ($gradebook): array {
                $score = $gradebook->scoreFor($enrolment->user, $enrolment->batch->course);

                return [
                    'name' => $enrolment->user->name,
                    'email' => $enrolment->user->email,
                    'course' => $enrolment->batch->course->title,
                    'quiz_percent' => $score['quiz_percent'],
                    'assignment_percent' => $score['assignment_percent'],
                    'weighted_percent' => $score['weighted_percent'],
                    'standing' => $gradebook->standingFor($score),
                ];
            });
    }
}
