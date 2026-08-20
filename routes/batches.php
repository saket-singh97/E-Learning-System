<?php

use App\Http\Controllers\Batches\ZoomOAuthController;
use Illuminate\Support\Facades\Route;

// role-gated in addition to the ZoomConnection page's own panel-level
// institute_admin authMiddleware (CLAUDE.md's EnsureRole double-check
// posture) — this route is reachable directly, not only through a link the
// page itself renders.
Route::get('/zoom/connect', [ZoomOAuthController::class, 'redirect'])
    ->middleware(['auth', 'role:institute_admin'])
    ->name('zoom.oauth.redirect');
