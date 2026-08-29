<?php

namespace App\Domains\Fees\Services;

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Comms\Jobs\Concerns\EscapesCsvFormulas;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Models\FeePayment;
use App\Domains\Fees\Models\PaymentHistoryImportBatch;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\InvalidPaymentHistoryImportFileException;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * S21 B12 (V14 pt.3, design doc §4 decision 7) — backfills already-settled
 * history from an institute's previous LMS: writes `FeeInvoice`/`FeePayment`
 * directly as already-paid/captured, never through
 * `FeeInvoiceService::createPaymentOrder()` or `PaymentReconciler` — no
 * live gateway call is made or should be, and no `PaymentCaptured` event
 * fires for imported history (that event means "a real payment just
 * happened," never true here). Same dry-run/per-row-error shape as
 * B10/B11, run fully synchronously.
 */
class PaymentHistoryCsvImporter
{
    use EscapesCsvFormulas;

    private const REQUIRED_HEADERS = ['email', 'batch', 'invoice_number', 'amount', 'gst_amount', 'total_amount', 'paid_at'];

    public function import(string $csvContents, User $uploader, bool $dryRun): PaymentHistoryImportBatch
    {
        $csvContents = preg_replace('/^\xEF\xBB\xBF/', '', $csvContents);

        $lines = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', trim($csvContents)),
            fn (string $line): bool => $line !== '',
        ));

        if ($lines === []) {
            throw InvalidPaymentHistoryImportFileException::empty();
        }

        $header = array_map(fn (string $h): string => strtolower(trim($h)), str_getcsv(array_shift($lines)));

        if (array_diff(self::REQUIRED_HEADERS, $header) !== []) {
            throw InvalidPaymentHistoryImportFileException::badHeaders();
        }

        if ($lines === []) {
            throw InvalidPaymentHistoryImportFileException::empty();
        }

        $tenantId = app(CurrentTenant::class)->id();
        $valid = [];
        $errors = [];

        foreach ($lines as $index => $line) {
            $rowNumber = $index + 2;
            $values = str_getcsv($line);

            if (count($values) !== count($header)) {
                $errors[] = ['row' => $rowNumber, 'message' => 'Row has a different number of columns than the header.'];

                continue;
            }

            $fields = array_combine($header, $values);

            $email = strtolower(trim((string) ($fields['email'] ?? '')));
            $batchSlug = trim((string) ($fields['batch'] ?? ''));
            $invoiceNumber = trim((string) ($fields['invoice_number'] ?? ''));
            $amount = trim((string) ($fields['amount'] ?? ''));
            $gstAmount = trim((string) ($fields['gst_amount'] ?? ''));
            $totalAmount = trim((string) ($fields['total_amount'] ?? ''));
            $paidAt = trim((string) ($fields['paid_at'] ?? ''));

            if ($invoiceNumber === '') {
                $errors[] = ['row' => $rowNumber, 'message' => 'Invoice number is required.'];

                continue;
            }

            if (! is_numeric($amount) || ! is_numeric($gstAmount) || ! is_numeric($totalAmount)) {
                $errors[] = ['row' => $rowNumber, 'message' => 'Amount, gst_amount, and total_amount must all be numbers.'];

                continue;
            }

            $paidAtDate = strtotime($paidAt);

            if ($paidAtDate === false) {
                $errors[] = ['row' => $rowNumber, 'message' => "\"{$paidAt}\" is not a valid paid_at date."];

                continue;
            }

            $student = User::where('tenant_id', $tenantId)->where('email', $email)->first();

            if ($student === null) {
                $errors[] = ['row' => $rowNumber, 'message' => "No student found with email \"{$email}\" in this institute."];

                continue;
            }

            $batch = Batch::where('slug', $batchSlug)->first();

            if ($batch === null) {
                $errors[] = ['row' => $rowNumber, 'message' => "No batch found with slug \"{$batchSlug}\"."];

                continue;
            }

            $enrolment = Enrolment::where('batch_id', $batch->id)->where('user_id', $student->id)->first();

            if ($enrolment === null) {
                $errors[] = ['row' => $rowNumber, 'message' => "No enrolment found for \"{$email}\" in batch \"{$batchSlug}\" — import the enrolment first."];

                continue;
            }

            $valid[] = [
                'row' => $rowNumber,
                'user_id' => $student->id,
                'batch_id' => $batch->id,
                'enrolment_id' => $enrolment->id,
                'invoice_number' => $invoiceNumber,
                'amount' => (float) $amount,
                'gst_amount' => (float) $gstAmount,
                'total_amount' => (float) $totalAmount,
                'paid_at' => date('Y-m-d H:i:s', $paidAtDate),
            ];
        }

        if (! $dryRun && $valid !== []) {
            $this->writeHistory($valid, Tenant::findOrFail($tenantId));
        }

        $reportPath = null;

        if ($errors !== []) {
            $reportPath = "payment-history-imports/{$tenantId}-".uniqid().'-errors.csv';
            Storage::disk('local')->put($reportPath, $this->buildErrorReportCsv($errors));
        }

        return PaymentHistoryImportBatch::create([
            'uploaded_by_user_id' => $uploader->id,
            'status' => $dryRun ? 'dry_run' : 'completed',
            'total_rows' => count($lines),
            'error_rows' => count($errors),
            'dry_run' => $dryRun,
            'error_report_path' => $reportPath,
        ]);
    }

    /**
     * @param  array<int, array{row: int, user_id: int, batch_id: int, enrolment_id: int, invoice_number: string, amount: float, gst_amount: float, total_amount: float, paid_at: string}>  $rows
     */
    protected function writeHistory(array $rows, Tenant $tenant): int
    {
        return DB::transaction(function () use ($rows, $tenant): int {
            Tenant::whereKey($tenant->id)->lockForUpdate()->first();

            $sequenceNumber = (int) FeeInvoice::where('tenant_id', $tenant->id)->max('sequence_number');

            foreach ($rows as $row) {
                $sequenceNumber++;

                $invoice = FeeInvoice::create([
                    'batch_id' => $row['batch_id'],
                    'enrolment_id' => $row['enrolment_id'],
                    'user_id' => $row['user_id'],
                    'sequence_number' => $sequenceNumber,
                    'invoice_number' => $row['invoice_number'],
                    'description' => 'Imported payment history',
                    'amount' => $row['amount'],
                    'gst_amount' => $row['gst_amount'],
                    'total_amount' => $row['total_amount'],
                    'due_date' => $row['paid_at'],
                    'status' => 'paid',
                    'paid_at' => $row['paid_at'],
                ]);

                FeePayment::create([
                    'fee_invoice_id' => $invoice->id,
                    'provider' => 'imported',
                    'provider_order_id' => 'imported-'.uniqid('', true),
                    'idempotency_key' => 'imported-'.uniqid('', true),
                    'amount' => $row['total_amount'],
                    'status' => 'captured',
                ]);
            }

            return count($rows);
        });
    }

    /**
     * @param  array<int, array{row: int, message: string}>  $errors
     */
    protected function buildErrorReportCsv(array $errors): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['Row', 'Error']);

        foreach ($errors as $error) {
            fputcsv($handle, [$error['row'], $this->csvSafe($error['message'])]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
