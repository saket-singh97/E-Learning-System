<?php

namespace App\Domains\Fees\Filament\Resources\PaymentHistoryImportBatches\Pages;

use App\Domains\Fees\Filament\Resources\PaymentHistoryImportBatches\PaymentHistoryImportBatchResource;
use App\Domains\Fees\Services\PaymentHistoryCsvImporter;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class CreatePaymentHistoryImportBatch extends CreateRecord
{
    protected static string $resource = PaymentHistoryImportBatchResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $csvPath = is_array($data['csv']) ? array_values($data['csv'])[0] : $data['csv'];
        $csvContents = Storage::disk('local')->get($csvPath);

        $batch = app(PaymentHistoryCsvImporter::class)->import($csvContents, auth()->user(), (bool) ($data['dry_run'] ?? false));

        Storage::disk('local')->delete($csvPath);

        return $batch;
    }
}
