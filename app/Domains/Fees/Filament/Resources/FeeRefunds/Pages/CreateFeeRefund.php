<?php

namespace App\Domains\Fees\Filament\Resources\FeeRefunds\Pages;

use App\Domains\Fees\Filament\Resources\FeeRefunds\FeeRefundResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFeeRefund extends CreateRecord
{
    protected static string $resource = FeeRefundResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['requested_by_user_id'] = auth()->id();
        $data['status'] = 'pending';

        return $data;
    }
}
