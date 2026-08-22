<?php

use App\Domains\Batches\Models\Batch;
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
