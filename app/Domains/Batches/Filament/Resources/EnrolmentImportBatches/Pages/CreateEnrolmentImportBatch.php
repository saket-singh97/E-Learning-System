<?php

namespace App\Domains\Batches\Filament\Resources\EnrolmentImportBatches\Pages;

use App\Domains\Batches\Filament\Resources\EnrolmentImportBatches\EnrolmentImportBatchResource;
use App\Domains\Batches\Services\EnrolmentCsvImporter;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class CreateEnrolmentImportBatch extends CreateRecord
{
    protected static string $resource = EnrolmentImportBatchResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $csvPath = is_array($data['csv']) ? array_values($data['csv'])[0] : $data['csv'];
        $csvContents = Storage::disk('local')->get($csvPath);

        $batch = app(EnrolmentCsvImporter::class)->import($csvContents, auth()->user(), (bool) ($data['dry_run'] ?? false));

        Storage::disk('local')->delete($csvPath);

        return $batch;
    }
}
