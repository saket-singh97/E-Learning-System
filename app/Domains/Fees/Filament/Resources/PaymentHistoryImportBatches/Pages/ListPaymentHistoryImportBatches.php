<?php

namespace App\Domains\Fees\Filament\Resources\PaymentHistoryImportBatches\Pages;

use App\Domains\Fees\Filament\Resources\PaymentHistoryImportBatches\PaymentHistoryImportBatchResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPaymentHistoryImportBatches extends ListRecords
{
    protected static string $resource = PaymentHistoryImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Upload CSV'),
        ];
    }
}
