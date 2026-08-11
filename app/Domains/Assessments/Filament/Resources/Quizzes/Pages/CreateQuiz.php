<?php

namespace App\Domains\Assessments\Filament\Resources\Quizzes\Pages;

use App\Domains\Assessments\Filament\Resources\Quizzes\QuizResource;
use Filament\Resources\Pages\CreateRecord;

class CreateQuiz extends CreateRecord
{
    protected static string $resource = QuizResource::class;
}
