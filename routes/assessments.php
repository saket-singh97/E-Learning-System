<?php

use App\Http\Controllers\Assessments\ShowQuizController;
use Illuminate\Support\Facades\Route;

Route::get('/quizzes/{quiz}', ShowQuizController::class)
    ->name('quizzes.show');
