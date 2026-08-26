<?php

use App\Domains\Batches\Models\Batch;
use App\Http\Controllers\Comms\DownloadAttendanceExportController;
use App\Http\Controllers\Comms\DownloadCompletionExportController;
use App\Http\Controllers\Comms\DownloadEngagementExportController;
use App\Http\Controllers\Comms\DownloadEnrolmentExportController;
use App\Http\Controllers\Comms\DownloadRevenueExportController;
use App\Http\Controllers\Comms\DownloadSurveyExportController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/parent/dashboard', fn () => view('parent.dashboard'))
    ->name('parent.dashboard');

// No 'auth' middleware — same "no single named login route" reasoning
// MyWishlist's own docblock established (S13 B3); a guest sees a
// log-in prompt instead of a 500.
Route::get('/messages', fn () => view('comms.messages'))
    ->name('messages.index');

// No 'auth' middleware, same reasoning as /messages above — a guest sees
// a log-in prompt inside the component rather than a 500. Batch resolved
// via $request->route() (CLAUDE.md's domain-route splicing gotcha), not
// a type-hinted Batch $batch parameter.
Route::get('/batches/{batch}/forum', function (Request $request) {
    $batch = Batch::findOrFail($request->route('batch'));

    return view('comms.batch-forum', ['batch' => $batch]);
})->name('batch-forum.show');

// S19 N14 — no 'auth' middleware, same reasoning as /messages above.
Route::get('/forum', fn () => view('comms.general-forum'))
    ->name('general-forum.show');

// S19 N15 — no 'auth' middleware, same reasoning as /messages above.
Route::get('/surveys', fn () => view('comms.survey-runner'))
    ->name('survey-runner.show');

// S19 N16 — no 'auth' middleware, same reasoning as /messages above.
Route::get('/help-desk', fn () => view('comms.help-desk-ticket-form'))
    ->name('help-desk.show');

Route::get('/exports/attendance-register', DownloadAttendanceExportController::class)
    ->name('comms.attendance-export.download');

Route::get('/exports/revenue-register', DownloadRevenueExportController::class)
    ->name('comms.revenue-export.download');

Route::get('/exports/engagement-register', DownloadEngagementExportController::class)
    ->name('comms.engagement-export.download');

Route::get('/exports/enrolment-register', DownloadEnrolmentExportController::class)
    ->name('comms.enrolment-export.download');

Route::get('/exports/completion-register', DownloadCompletionExportController::class)
    ->name('comms.completion-export.download');

// S19 N15 pt.3 — per-survey, not tenant-wide like the exports above.
Route::get('/surveys/{survey}/export', DownloadSurveyExportController::class)
    ->name('comms.survey-export.download');
