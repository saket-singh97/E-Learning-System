<?php

namespace App\Domains\Assessments\Filament\Resources\TestSeries\Pages;

use App\Domains\Assessments\Filament\Resources\TestSeries\TestSeriesResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTestSeries extends CreateRecord
{
    protected static string $resource = TestSeriesResource::class;
}
