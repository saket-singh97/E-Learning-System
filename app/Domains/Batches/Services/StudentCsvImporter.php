<?php

namespace App\Domains\Batches\Services;

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\StudentImportBatch;
use App\Exceptions\InvalidStudentImportFileException;
use App\Models\User;

/**
 * Phase 1 only (design decision 6) — synchronous, run inline on upload,
 * cheap enough for up to 5000 rows. File-shape errors (bad headers, no
 * data rows) refuse immediately, before any StudentImportBatch row is
 * even written — the user gets that feedback without waiting on a queue.
 * Per-row errors don't refuse the whole file; they're returned alongside
 * the valid rows for the caller (B10's upload action) to hand off to the
 * queued creation job. Nothing here is Excel::import() (maatwebsite/excel
 * has never been used for import in this app, this is the first) — a
 * plain CSV needs no spreadsheet-format handling.
 */
class StudentCsvImporter
{
    private const REQUIRED_HEADERS = ['name', 'email', 'batch'];

    /**
     * @return array{importBatch: StudentImportBatch, valid: array<int, array{row: int, name: string, email: string, phone: ?string, batch_id: int}>, errors: array<int, array{row: int, message: string}>}
     */
    public function parse(string $csvContents, User $uploader): array
    {
        $lines = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', trim($csvContents)),
            fn (string $line): bool => $line !== '',
        ));

        if ($lines === []) {
            throw InvalidStudentImportFileException::empty();
        }

        $header = array_map(fn (string $h): string => strtolower(trim($h)), str_getcsv(array_shift($lines)));

        if (array_diff(self::REQUIRED_HEADERS, $header) !== []) {
            throw InvalidStudentImportFileException::badHeaders();
        }

        if ($lines === []) {
            throw InvalidStudentImportFileException::empty();
        }

        $valid = [];
        $errors = [];
        $seenEmails = [];

        foreach ($lines as $index => $line) {
            $rowNumber = $index + 2; // header occupies row 1
            $fields = array_combine($header, str_getcsv($line));

            $name = trim((string) ($fields['name'] ?? ''));
            $email = strtolower(trim((string) ($fields['email'] ?? '')));
            $phone = trim((string) ($fields['phone'] ?? ''));
            $batchSlug = trim((string) ($fields['batch'] ?? ''));

            if ($name === '') {
                $errors[] = ['row' => $rowNumber, 'message' => 'Name is required.'];

                continue;
            }

            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = ['row' => $rowNumber, 'message' => 'A valid email is required.'];

                continue;
            }

            if (isset($seenEmails[$email])) {
                $errors[] = ['row' => $rowNumber, 'message' => "Duplicate email in this file (also row {$seenEmails[$email]})."];

                continue;
            }

            $batch = Batch::where('slug', $batchSlug)->first();

            if ($batch === null) {
                $errors[] = ['row' => $rowNumber, 'message' => "No batch found with slug \"{$batchSlug}\"."];

                continue;
            }

            $seenEmails[$email] = $rowNumber;

            $valid[] = [
                'row' => $rowNumber,
                'name' => $name,
                'email' => $email,
                'phone' => $phone !== '' ? $phone : null,
                'batch_id' => $batch->id,
            ];
        }

        $importBatch = StudentImportBatch::create([
            'uploaded_by_user_id' => $uploader->id,
            'status' => 'pending',
            'total_rows' => count($lines),
            'error_count' => count($errors),
        ]);

        return ['importBatch' => $importBatch, 'valid' => $valid, 'errors' => $errors];
    }
}
