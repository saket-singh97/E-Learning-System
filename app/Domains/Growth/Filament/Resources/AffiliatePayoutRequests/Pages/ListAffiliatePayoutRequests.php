<?php

namespace App\Domains\Growth\Filament\Resources\AffiliatePayoutRequests\Pages;

use App\Domains\Growth\Filament\Resources\AffiliatePayoutRequests\AffiliatePayoutRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListAffiliatePayoutRequests extends ListRecords
{
    protected static string $resource = AffiliatePayoutRequestResource::class;
}
