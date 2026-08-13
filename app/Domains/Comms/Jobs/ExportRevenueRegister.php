<?php

namespace App\Domains\Comms\Jobs;

use App\Domains\Comms\Jobs\Concerns\EscapesCsvFormulas;
use App\Domains\Fees\Models\FeeInvoice;
use App\Support\Tenancy\RebindsTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Copies ExportAttendanceRegister's exact shape (S8 B6, S9 B11's own plan
 * note): ShouldQueue + RebindsTenant + Storage::disk('local') + a
 * deterministic per-tenant path, not a new architectural pattern. One row
 * per invoice (not per payment) — FeeInvoice.status/paid_at are already
 * the authoritative source SendFeeReminders/FlagOverdueInvoices both treat
 * as such, so this reads them directly rather than joining FeePayment;
 * that also means pending/overdue amounts show up here too, which a
 * revenue register genuinely needs (a payments-only log would silently
 * drop everything not yet captured).
 */
class ExportRevenueRegister implements ShouldQueue
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
        return "revenue-exports/{$tenantId}.csv";
    }

    public function handle(): void
    {
        Storage::disk('local')->put(self::pathFor($this->tenant_id), $this->buildCsv());
    }

    protected function buildCsv(): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [
            'Invoice number', 'Student name', 'Student email', 'Batch',
            'Amount', 'GST amount', 'Discount amount', 'Total amount',
            'Status', 'Due date', 'Paid at',
        ]);

        FeeInvoice::with(['user', 'batch'])
            ->orderBy('due_date')
            ->chunk(200, function ($invoices) use ($handle): void {
                foreach ($invoices as $invoice) {
                    fputcsv($handle, [
                        $this->csvSafe($invoice->invoice_number),
                        $this->csvSafe($invoice->user?->name ?? 'Unknown'),
                        $this->csvSafe($invoice->user?->email ?? ''),
                        $this->csvSafe($invoice->batch?->title ?? 'Untitled'),
                        $invoice->amount,
                        $invoice->gst_amount,
                        $invoice->discount_amount,
                        $invoice->total_amount,
                        $invoice->status,
                        $invoice->due_date?->toDateString(),
                        $invoice->paid_at?->toDateTimeString(),
                    ]);
                }
            });

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
