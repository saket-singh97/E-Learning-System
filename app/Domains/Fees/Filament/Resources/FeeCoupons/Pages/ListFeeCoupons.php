<?php

namespace App\Domains\Fees\Filament\Resources\FeeCoupons\Pages;

use App\Domains\Fees\Filament\Resources\FeeCoupons\FeeCouponResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFeeCoupons extends ListRecords
{
    protected static string $resource = FeeCouponResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
