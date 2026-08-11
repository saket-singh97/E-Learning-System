<?php

namespace App\Domains\Assessments\Filament\Resources\QuizAnswers\Pages;

use App\Domains\Assessments\Filament\Resources\QuizAnswers\QuizAnswerResource;
use Filament\Resources\Pages\ListRecords;

class ListQuizAnswers extends ListRecords
{
    protected static string $resource = QuizAnswerResource::class;
}
