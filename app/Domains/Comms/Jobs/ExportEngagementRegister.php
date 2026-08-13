<?php

namespace App\Domains\Comms\Jobs;

use App\Domains\Batches\Models\Enrolment;
use App\Domains\Comms\Jobs\Concerns\EscapesCsvFormulas;
use App\Domains\Comms\Services\StudentMetrics;
use App\Models\User;
use App\Support\Tenancy\RebindsTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Copies ExportAttendanceRegister's exact shape (S8 B6, S9 B11's own plan
 * note). One row per student (not per student-per-course) — StudentMetrics
 * itself only ever computes a student's overall attendance/coverage/quiz
 * numbers, the same granularity StudentProgress (S8 B5) and the parent
 * portal already surface; a per-course breakdown would need a formal
 * per-course rollup that doesn't exist anywhere in this app, a
 * meaningfully bigger scope than this block's own "reuses StudentMetrics
 * per row" instruction. Roster query copied from StudentProgress's own
 * studentsQuery() — every actively enrolled student in the tenant, since
 * no instructor/admin-specific student ownership exists to scope it
 * tighter (same documented gap StudentProgress's own docblock names).
 */
class ExportEngagementRegister implements ShouldQueue
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
        return "engagement-exports/{$tenantId}.csv";
    }

    public function handle(): void
    {
        Storage::disk('local')->put(self::pathFor($this->tenant_id), $this->buildCsv());
    }

    protected function buildCsv(): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [
            'Student name', 'Student email', 'Attendance %', 'Video coverage %',
            'Quiz average %', 'Fee status',
        ]);

        $metrics = app(StudentMetrics::class);

        $this->studentsQuery()
            ->orderBy('name')
            ->chunk(200, function ($students) use ($handle, $metrics): void {
                foreach ($students as $student) {
                    $summary = $metrics->summarize($student);

                    fputcsv($handle, [
                        $this->csvSafe($student->name),
                        $this->csvSafe($student->email),
                        $summary['attendance_percent'],
                        $summary['coverage_percent'],
                        $summary['quiz_average_percent'],
                        $summary['fee_status'],
                    ]);
                }
            });

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    protected function studentsQuery(): Builder
    {
        return User::role('student')
            ->where('tenant_id', $this->tenant_id)
            ->whereIn('id', Enrolment::where('status', 'active')->pluck('user_id'));
    }
}
