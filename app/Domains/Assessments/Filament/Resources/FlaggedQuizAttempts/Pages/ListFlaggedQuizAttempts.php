<?php

namespace App\Domains\Assessments\Filament\Resources\FlaggedQuizAttempts\Pages;

use App\Domains\Assessments\Filament\Resources\FlaggedQuizAttempts\FlaggedQuizAttemptResource;
use Filament\Resources\Pages\ListRecords;

class ListFlaggedQuizAttempts extends ListRecords
{
    protected static string $resource = FlaggedQuizAttemptResource::class;
}
