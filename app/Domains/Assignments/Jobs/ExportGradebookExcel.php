<?php

namespace App\Domains\Assignments\Jobs;

use App\Domains\Assignments\Exports\GradebookExport;
use App\Support\Tenancy\RebindsTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Same `ShouldQueue` + `RebindsTenant` shape every other export job in this
 * app already uses (S9 B11, this domain's own `ExportGradebookCsv` B9) —
 * `Excel::store()` writes through `maatwebsite/excel`'s own disk handling
 * rather than a raw string build, the one structural difference from the
 * CSV job.
 */
class ExportGradebookExcel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
        return "gradebook-exports/{$tenantId}.xlsx";
    }

    public function handle(): void
    {
        Excel::store(new GradebookExport($this->tenant_id), self::pathFor($this->tenant_id), 'local');
    }
}
