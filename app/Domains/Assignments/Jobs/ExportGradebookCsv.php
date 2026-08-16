<?php

namespace App\Domains\Assignments\Jobs;

use App\Domains\Assignments\Jobs\Concerns\BuildsGradebookRows;
use App\Domains\Comms\Jobs\Concerns\EscapesCsvFormulas;
use App\Support\Tenancy\RebindsTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Same `ShouldQueue` + `RebindsTenant` + `Storage::disk('local')` +
 * `EscapesCsvFormulas` shape `ExportEngagementRegister`/`ExportRevenueRegister`
 * (S9 B11) already established — a fourth copy of an existing pattern, not
 * a new one (plan doc's own instruction). One row per active `Enrolment`
 * (student × course), the same granularity the `Gradebook` Filament pages
 * (B8) already show — not per-student, since `GradebookService::scoreFor()`
 * is always course-scoped.
 *
 * Row-building retrofitted onto `BuildsGradebookRows` (B10) once the
 * Excel/PDF exports needed the identical rows — see that trait's own
 * docblock for why this is no longer chunked.
 */
class ExportGradebookCsv implements ShouldQueue
{
    use BuildsGradebookRows, Dispatchable, EscapesCsvFormulas, InteractsWithQueue, Queueable, SerializesModels;

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
        return "gradebook-exports/{$tenantId}.csv";
    }

    public function handle(): void
    {
        Storage::disk('local')->put(self::pathFor($this->tenant_id), $this->buildCsv());
    }

    protected function buildCsv(): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [
            'Student name', 'Student email', 'Course', 'Quiz %', 'Assignment %', 'Weighted %', 'Standing',
        ]);

        foreach ($this->gradebookRows($this->tenant_id) as $row) {
            fputcsv($handle, [
                $this->csvSafe($row['name']),
                $this->csvSafe($row['email']),
                $this->csvSafe($row['course']),
                $row['quiz_percent'],
                $row['assignment_percent'],
                $row['weighted_percent'],
                $row['standing'],
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
