<?php

namespace App\Domains\Billing\Filament\Resources\SubscriptionInvoices\Pages;

use App\Domains\Billing\Filament\Resources\SubscriptionInvoices\SubscriptionInvoiceResource;
use Filament\Resources\Pages\ListRecords;

class ListSubscriptionInvoices extends ListRecords
{
    protected static string $resource = SubscriptionInvoiceResource::class;
}
