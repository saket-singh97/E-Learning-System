<?php

namespace App\Domains\Batches\Services;

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\EnrolmentImportBatch;
use App\Domains\Comms\Jobs\Concerns\EscapesCsvFormulas;
use App\Exceptions\InvalidEnrolmentImportFileException;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Storage;

/**
 * S21 B11 (V14 pt.2, design doc §4 decision 7) — same dry-run/per-row-error
 * shape CourseCsvImporter (B10) established. References only pre-existing
 * students/batches (this is *enrolment* import, not student import — F9/S15
 * already owns creating new student rows from a CSV); a row whose email or
 * batch slug doesn't already exist is a per-row error, never a new record.
 * Cheap enough to run fully synchronously like B10 — `addManually()` is a
 * couple of lookups plus one insert, no external I/O.
 */
class EnrolmentCsvImporter
{
    use EscapesCsvFormulas;

    private const REQUIRED_HEADERS = ['email', 'batch'];

    public function import(string $csvContents, User $uploader, bool $dryRun): EnrolmentImportBatch
    {
        $csvContents = preg_replace('/^\xEF\xBB\xBF/', '', $csvContents);

        $lines = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', trim($csvContents)),
            fn (string $line): bool => $line !== '',
        ));

        if ($lines === []) {
            throw InvalidEnrolmentImportFileException::empty();
        }

        $header = array_map(fn (string $h): string => strtolower(trim($h)), str_getcsv(array_shift($lines)));

        if (array_diff(self::REQUIRED_HEADERS, $header) !== []) {
            throw InvalidEnrolmentImportFileException::badHeaders();
        }

        if ($lines === []) {
            throw InvalidEnrolmentImportFileException::empty();
        }

        $tenantId = app(CurrentTenant::class)->id();
        $valid = [];
        $errors = [];
        $seenPairs = [];

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

            if ($email === '') {
                $errors[] = ['row' => $rowNumber, 'message' => 'Email is required.'];

                continue;
            }

            if ($batchSlug === '') {
                $errors[] = ['row' => $rowNumber, 'message' => 'Batch is required.'];

                continue;
            }

            $pairKey = "{$email}|{$batchSlug}";

            if (isset($seenPairs[$pairKey])) {
                $errors[] = ['row' => $rowNumber, 'message' => "Duplicate email+batch in this file (also row {$seenPairs[$pairKey]})."];

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

            $seenPairs[$pairKey] = $rowNumber;

            $valid[] = ['row' => $rowNumber, 'student_id' => $student->id, 'batch_id' => $batch->id];
        }

        $successCount = 0;

        if (! $dryRun) {
            foreach ($valid as $row) {
                app(BatchEnrolmentService::class)->addManually(
                    Batch::findOrFail($row['batch_id']),
                    User::findOrFail($row['student_id']),
                    $uploader,
                );

                $successCount++;
            }
        }

        $reportPath = null;

        if ($errors !== []) {
            $reportPath = "enrolment-imports/{$tenantId}-".uniqid().'-errors.csv';
            Storage::disk('local')->put($reportPath, $this->buildErrorReportCsv($errors));
        }

        return EnrolmentImportBatch::create([
            'uploaded_by_user_id' => $uploader->id,
            'status' => $dryRun ? 'dry_run' : 'completed',
            'total_rows' => count($lines),
            'error_rows' => count($errors),
            'dry_run' => $dryRun,
            'error_report_path' => $reportPath,
        ]);
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
