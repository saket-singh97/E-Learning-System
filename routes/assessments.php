<?php

use App\Domains\Assessments\Models\TestSeries;
use App\Http\Controllers\Assessments\ShowQuizController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/quizzes/{quiz}', ShowQuizController::class)
    ->name('quizzes.show');

// S16 B13 (I10 pt.2) — no existing student-facing page to embed
// TestSeriesResults on, same "add a small host route" shape B3's
// /batch-sessions/{session}/check-in already took. Auth-required —
// a viewer with no identity has no own rank/percentile to compute.
Route::get('/test-series/{series}/results', function (Request $request) {
    $series = TestSeries::findOrFail($request->route('series'));

    return view('assessments.test-series-results', ['series' => $series]);
})->middleware(['auth'])->name('test-series.results');
