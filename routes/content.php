<?php

use App\Domains\Content\Models\CourseSection;
use App\Http\Controllers\Content\DownloadCourseImportErrorReportController;
use App\Http\Controllers\Content\DownloadLessonPdfController;
use App\Http\Controllers\Content\DownloadLessonSubtitleController;
use App\Http\Controllers\Content\JoinLiveClassController;
use App\Http\Controllers\Content\ShowSyllabusController;
use App\Http\Controllers\Content\StreamLessonAudioController;
use App\Http\Controllers\Content\StreamLessonController;
use App\Http\Controllers\Content\WatchHeartbeatController;
use App\Http\Controllers\Content\WatchLessonController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public — same "published is readable by anyone" posture ShowSyllabusController
// already applies (design doc §2.2), no auth middleware.
Route::get('/courses', fn () => view('content.catalog'))
    ->name('catalog.index');

// No 'auth' middleware here — this app has no single named 'login' route
// (3 separate Filament panel logins + StudentPortal, embedded rather than
// routed) for the default Authenticate middleware to redirect a guest to,
// so a hard gate 500s instead of redirecting. MyWishlist itself renders a
// guest-appropriate empty state, same no-op-for-guest posture CourseCatalog/
// BatchCatalog already established.
Route::get('/wishlist', fn () => view('content.my-wishlist'))
    ->name('wishlist.index');

Route::get('/watch/{lesson}', StreamLessonController::class)
    ->middleware('signed')
    ->name('watch.stream');

// S22 B8 — audio-only mode's own stream endpoint, same 'signed' shape as
// watch.stream/watch.pdf/watch.subtitle above.
Route::get('/watch/{lesson}/audio', StreamLessonAudioController::class)
    ->middleware('signed')
    ->name('watch.audio');

// S21 B10 — same double-check posture as routes/batches.php's own
// /student-imports/{importBatch}/error-report: role:institute_admin
// middleware plus the controller's own tenant-scoped lookup.
Route::get('/course-imports/{importBatch}/error-report', DownloadCourseImportErrorReportController::class)
    ->middleware(['auth', 'role:institute_admin'])
    ->name('course-import.error-report.download');

Route::get('/watch/{lesson}/pdf', DownloadLessonPdfController::class)
    ->middleware('signed')
    ->name('watch.pdf');

Route::get('/watch/{lesson}/subtitle', DownloadLessonSubtitleController::class)
    ->middleware('signed')
    ->name('watch.subtitle');

Route::post('/watch/{lesson}/heartbeat', WatchHeartbeatController::class)
    ->name('watch.heartbeat');

Route::get('/lessons/{lesson}', WatchLessonController::class)
    ->name('lessons.watch');

Route::get('/courses/{course}', ShowSyllabusController::class)
    ->name('courses.show');

Route::get('/live-sessions/{session}/join', JoinLiveClassController::class)
    ->name('live.join');

// S22 B6 — same "add a small host route" shape as batches.php's own
// batch.daily-practice; no existing per-chapter page to embed FormulaSheet
// on. No auth middleware — same "published is readable by anyone" posture
// as /courses/{course} above (FormulaSheet's own isCourseVisibleTo() gate
// still blocks a draft course's chapter).
Route::get('/course-sections/{section}/formula-sheet', function (Request $request) {
    $section = CourseSection::findOrFail($request->route('section'));

    return view('content.chapter-formula-sheet', ['section' => $section]);
})->name('course-section.formula-sheet');
