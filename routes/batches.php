<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Http\Controllers\Batches\DownloadEnrolmentImportErrorReportController;
use App\Http\Controllers\Batches\DownloadIdCardController;
use App\Http\Controllers\Batches\DownloadStudentImportErrorReportController;
use App\Http\Controllers\Batches\ZoomOAuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// role-gated in addition to the ZoomConnection page's own panel-level
// institute_admin authMiddleware (CLAUDE.md's EnsureRole double-check
// posture) — this route is reachable directly, not only through a link the
// page itself renders.
Route::get('/zoom/connect', [ZoomOAuthController::class, 'redirect'])
    ->middleware(['auth', 'role:institute_admin'])
    ->name('zoom.oauth.redirect');

// No 'auth' middleware, same reasoning routes/comms.php's own /messages
// and /batches/{batch}/forum already use — a guest sees a log-in prompt
// inside the component rather than a 500. Batch resolved via
// $request->route() (CLAUDE.md's domain-route splicing gotcha), not a
// type-hinted Batch $batch parameter.
Route::get('/batches/{batch}/feedback', function (Request $request) {
    $batch = Batch::findOrFail($request->route('batch'));

    return view('batches.batch-feedback', ['batch' => $batch]);
})->name('batch-feedback.show');

// S16 B3 (H11 pt.2) — no existing student-facing "batch session" page to
// embed GeofenceCheckIn on, same "add a small host route" shape S15
// B6/B7 already took. Auth-required (unlike /batches/{batch}/feedback) —
// a check-in with no viewer has nothing to attribute the attendance row
// to, so there's no meaningful guest state to render here.
Route::get('/batch-sessions/{session}/check-in', function (Request $request) {
    $session = BatchSession::findOrFail($request->route('session'));

    return view('batches.batch-session-check-in', ['session' => $session]);
})->middleware(['auth'])->name('batch-session.check-in');

// S22 B4 — same "add a small host route" shape as the check-in route above;
// no existing student-facing "batch" page to embed DailyPracticeBoard on.
// Auth-required — the component's own enrolment gate has nothing to check
// against for a guest.
Route::get('/batches/{batch}/daily-practice', function (Request $request) {
    $batch = Batch::findOrFail($request->route('batch'));

    return view('batches.batch-daily-practice', ['batch' => $batch]);
})->middleware(['auth'])->name('batch.daily-practice');

// S23 B2 — same "add a small host route" shape as /daily-practice above;
// no existing student-facing "batch" page to embed CatchUpPlan on either.
Route::get('/batches/{batch}/catch-up', function (Request $request) {
    $batch = Batch::findOrFail($request->route('batch'));

    return view('batches.batch-catch-up', ['batch' => $batch]);
})->middleware(['auth'])->name('batch.catch-up');

// Same double-check posture as /zoom/connect above — role:institute_admin
// middleware plus the controller's own tenant-scoped lookup.
Route::get('/student-imports/{importBatch}/error-report', DownloadStudentImportErrorReportController::class)
    ->middleware(['auth', 'role:institute_admin'])
    ->name('student-import.error-report.download');

// S21 B11 — same double-check posture as the student-import route above.
Route::get('/enrolment-imports/{importBatch}/error-report', DownloadEnrolmentImportErrorReportController::class)
    ->middleware(['auth', 'role:institute_admin'])
    ->name('enrolment-import.error-report.download');

// No route-level role middleware — same bare shape
// DownloadGradebookPdfExportController/DownloadRevenueExportController
// already use, the role+tenant check lives entirely inside the
// controller (instructor as well as institute_admin, since either may
// issue/download an ID card via EnrolmentsRelationManager in either panel).
Route::get('/id-cards/{enrolment}/download', DownloadIdCardController::class)
    ->middleware(['auth'])
    ->name('id-card.download');
