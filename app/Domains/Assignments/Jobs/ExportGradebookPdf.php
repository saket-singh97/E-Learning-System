<?php

namespace App\Domains\Assignments\Jobs;

use App\Domains\Assignments\Jobs\Concerns\BuildsGradebookRows;
use App\Support\Tenancy\RebindsTenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * First use of barryvdh/laravel-dompdf (^3.1) in this app. No csvSafe()
 * guard here (unlike the CSV/Excel siblings) — a PDF is a rendered
 * document, not a spreadsheet a viewer's own app could reinterpret a cell
 * value as a live formula in, and Blade's {{ }} already HTML-escapes each
 * value into the table cell.
 */
class ExportGradebookPdf implements ShouldQueue
{
    use BuildsGradebookRows, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
        return "gradebook-exports/{$tenantId}.pdf";
    }

    public function handle(): void
    {
        Pdf::loadView('assignments.gradebook-pdf', ['rows' => $this->gradebookRows($this->tenant_id)])
            ->save(self::pathFor($this->tenant_id), 'local');
    }
}
