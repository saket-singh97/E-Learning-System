<?php

namespace App\Domains\Comms\Jobs;

use App\Domains\Assignments\Services\GradebookService;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Comms\Jobs\Concerns\EscapesCsvFormulas;
use App\Support\Tenancy\RebindsTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * S3's other real gap (design decision 12) — same ExportEnrolmentRegister
 * (B18) shape. Completion itself reuses GradebookService::isComplete()/
 * scoreFor(), the exact definition CheckCourseCompletionAndIssueCertificates
 * (S10) already established, not re-derived here.
 */
class ExportCompletionRegister implements ShouldQueue
{
    use Dispatchable, EscapesCsvFormulas, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(
        public readonly ?int $tenant_id,
    ) {}

    public function middleware(): array
    {
        return [new RebindsTenant];
    }

    public static function pathFor(int $tenantId): string
    {
        return "completion-exports/{$tenantId}.csv";
    }

    public function handle(GradebookService $gradebook): void
    {
        Storage::disk('local')->put(self::pathFor($this->tenant_id), $this->buildCsv($gradebook));
    }

    protected function buildCsv(GradebookService $gradebook): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['Student name', 'Student email', 'Batch', 'Course', 'Completed', 'Weighted %']);

        Enrolment::where('status', 'active')
            // Same soft-deleted batch/course guard CheckCourseCompletionAndIssueCertificates
            // (S10) already carries — an active enrolment can point at a
            // soft-deleted batch or course.
            ->whereHas('batch', fn ($query) => $query->whereHas('course'))
            ->orderBy('requested_at')
            ->with(['user', 'batch.course'])
            ->chunk(200, function ($enrolments) use ($handle, $gradebook): void {
                foreach ($enrolments as $enrolment) {
                    $student = $enrolment->user;
                    $course = $enrolment->batch->course;

                    $complete = $student !== null && $gradebook->isComplete($student, $course);
                    $weightedPercent = $student !== null
                        ? $gradebook->scoreFor($student, $course)['weighted_percent']
                        : null;

                    fputcsv($handle, [
                        $this->csvSafe($student?->name ?? 'Unknown'),
                        $this->csvSafe($student?->email ?? ''),
                        $this->csvSafe($enrolment->batch->title),
                        $this->csvSafe($course->title),
                        $complete ? 'Yes' : 'No',
                        $weightedPercent,
                    ]);
                }
            });

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
