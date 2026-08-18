<?php

namespace App\Domains\Fees\Filament\Resources\FeePayments\Pages;

use App\Domains\Fees\Filament\Resources\FeePayments\FeePaymentResource;
use Filament\Resources\Pages\ListRecords;

class ListFeePayments extends ListRecords
{
    protected static string $resource = FeePaymentResource::class;
}
