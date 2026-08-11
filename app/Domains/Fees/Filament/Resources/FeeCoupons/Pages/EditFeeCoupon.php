<?php

namespace App\Domains\Fees\Filament\Resources\FeeCoupons\Pages;

use App\Domains\Fees\Filament\Resources\FeeCoupons\FeeCouponResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFeeCoupon extends EditRecord
{
    protected static string $resource = FeeCouponResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
