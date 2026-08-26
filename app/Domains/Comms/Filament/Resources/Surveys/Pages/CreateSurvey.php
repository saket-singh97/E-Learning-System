<?php

namespace App\Domains\Comms\Filament\Resources\Surveys\Pages;

use App\Domains\Comms\Filament\Resources\Surveys\SurveyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSurvey extends CreateRecord
{
    protected static string $resource = SurveyResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by_user_id'] = auth()->id();

        return $data;
    }
}
