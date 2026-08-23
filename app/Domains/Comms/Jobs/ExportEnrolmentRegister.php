<?php

namespace App\Domains\Comms\Jobs;

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
 * S3's own real gap (design decision 12) — the fourth export built this
 * exact `ExportAttendanceRegister` shape (`RebindsTenant`,
 * `Storage::disk('local')`, `EscapesCsvFormulas`, no new schema —
 * readiness read straight off `Storage::exists()`), no new pattern.
 */
class ExportEnrolmentRegister implements ShouldQueue
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
        return "enrolment-exports/{$tenantId}.csv";
    }

    public function handle(): void
    {
        Storage::disk('local')->put(self::pathFor($this->tenant_id), $this->buildCsv());
    }

    protected function buildCsv(): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['Student name', 'Student email', 'Batch', 'Status', 'Source', 'Requested at', 'Decided at']);

        Enrolment::with(['user', 'batch'])
            ->orderBy('requested_at')
            ->chunk(200, function ($enrolments) use ($handle): void {
                foreach ($enrolments as $enrolment) {
                    fputcsv($handle, [
                        $this->csvSafe($enrolment->user?->name ?? 'Unknown'),
                        $this->csvSafe($enrolment->user?->email ?? ''),
                        $this->csvSafe($enrolment->batch?->title ?? 'Unknown'),
                        $enrolment->status,
                        $enrolment->source,
                        $enrolment->requested_at?->toDateTimeString(),
                        $enrolment->decided_at?->toDateTimeString(),
                    ]);
                }
            });

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
