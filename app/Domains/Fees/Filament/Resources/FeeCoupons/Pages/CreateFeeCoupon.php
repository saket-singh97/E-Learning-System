<?php

namespace App\Domains\Fees\Filament\Resources\FeeCoupons\Pages;

use App\Domains\Fees\Filament\Resources\FeeCoupons\FeeCouponResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFeeCoupon extends CreateRecord
{
    protected static string $resource = FeeCouponResource::class;
}
