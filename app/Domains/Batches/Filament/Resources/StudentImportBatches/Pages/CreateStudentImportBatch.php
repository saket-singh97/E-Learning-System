<?php

namespace App\Domains\Batches\Filament\Resources\StudentImportBatches\Pages;

use App\Domains\Batches\Filament\Resources\StudentImportBatches\StudentImportBatchResource;
use App\Domains\Batches\Jobs\ImportStudentsFromCsv;
use App\Domains\Batches\Services\StudentCsvImporter;
use App\Support\Tenancy\CurrentTenant;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 1 (StudentCsvImporter::parse(), synchronous) runs here, in the
 * same request — the admin gets immediate feedback on file-shape errors
 * without waiting on a queue (design decision 6). Phase 2 is always
 * dispatched, even with zero valid rows, so there is exactly one place
 * that writes the final error-report CSV and sets status='done'
 * (ImportStudentsFromCsv::handle()), not duplicated here for the
 * all-rows-invalid edge case.
 */
class CreateStudentImportBatch extends CreateRecord
{
    protected static string $resource = StudentImportBatchResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $csvPath = is_array($data['csv']) ? array_values($data['csv'])[0] : $data['csv'];
        $csvContents = Storage::disk('local')->get($csvPath);

        $result = app(StudentCsvImporter::class)->parse($csvContents, auth()->user());

        Storage::disk('local')->delete($csvPath);

        ImportStudentsFromCsv::dispatch(
            app(CurrentTenant::class)->id(),
            $result['importBatch']->id,
            $result['valid'],
            $result['errors'],
        );

        return $result['importBatch'];
    }
}
