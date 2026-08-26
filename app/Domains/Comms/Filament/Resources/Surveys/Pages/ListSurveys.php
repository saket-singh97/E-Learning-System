<?php

namespace App\Domains\Comms\Filament\Resources\Surveys\Pages;

use App\Domains\Comms\Filament\Resources\Surveys\SurveyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSurveys extends ListRecords
{
    protected static string $resource = SurveyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
