<?php

namespace App\Domains\Fees\Filament\Resources\FeeRefunds\Pages;

use App\Domains\Fees\Filament\Resources\FeeRefunds\FeeRefundResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFeeRefunds extends ListRecords
{
    protected static string $resource = FeeRefundResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
