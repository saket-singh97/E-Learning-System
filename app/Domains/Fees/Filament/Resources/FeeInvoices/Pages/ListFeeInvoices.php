<?php

namespace App\Domains\Fees\Filament\Resources\FeeInvoices\Pages;

use App\Domains\Fees\Filament\Resources\FeeInvoices\FeeInvoiceResource;
use Filament\Resources\Pages\ListRecords;

class ListFeeInvoices extends ListRecords
{
    protected static string $resource = FeeInvoiceResource::class;
}
