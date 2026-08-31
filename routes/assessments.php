<?php

use App\Domains\Assessments\Models\TestSeries;
use App\Domains\Content\Models\Course;
use App\Http\Controllers\Assessments\DownloadHallTicketController;
use App\Http\Controllers\Assessments\DownloadOmrTemplateController;
use App\Http\Controllers\Assessments\DownloadRankCardController;
use App\Http\Controllers\Assessments\ShowQuizController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/quizzes/{quiz}', ShowQuizController::class)
    ->name('quizzes.show');

// B17 review pass — the generated OMR template PDF had no download path.
Route::get('/omr-templates/{template}/download', DownloadOmrTemplateController::class)
    ->middleware(['auth'])
    ->name('omr-templates.download');

// S16 B13 (I10 pt.2) — no existing student-facing page to embed
// TestSeriesResults on, same "add a small host route" shape B3's
// /batch-sessions/{session}/check-in already took. Auth-required —
// a viewer with no identity has no own rank/percentile to compute.
Route::get('/test-series/{series}/results', function (Request $request) {
    $series = TestSeries::findOrFail($request->route('series'));

    return view('assessments.test-series-results', ['series' => $series]);
})->middleware(['auth'])->name('test-series.results');

// S23 B16 (U15 pt.2) — student-owned PDF, same DownloadInvoiceController
// owner-or-staff gating shape (design §5 "gated the same as any other
// student-owned PDF").
Route::get('/rank-cards/{rankCard}/download', DownloadRankCardController::class)
    ->middleware(['auth'])
    ->name('rank-cards.download');

// S23 B17 (U14) — no 'auth', a scholarship-test registrant has no User row
// (design §4 decision 2). 'signed'-only, same DownloadCalendarFeedController
// posture.
Route::get('/hall-tickets/{hallTicket}/download', DownloadHallTicketController::class)
    ->middleware(['signed'])
    ->name('hall-tickets.download');

// S23 B6 — same "add a small host route" shape as routes/content.php's own
// course-section.formula-sheet. Question belongs to a course, not a batch
// (design doc §3.2), so this is course-scoped rather than reusing the
// batch-scoped DailyPracticeBoard/CatchUpPlan route shape. Auth-required —
// PyqBrowser::mount() itself enforces the actual enrolled-in-this-course
// gate (design §5 "enrolled + canStream()").
Route::get('/courses/{course}/pyq', function (Request $request) {
    $course = Course::findOrFail($request->route('course'));

    return view('assessments.course-pyq', ['course' => $course]);
})->middleware(['auth'])->name('course.pyq');
