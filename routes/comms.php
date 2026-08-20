<?php

use App\Http\Controllers\Comms\DownloadAttendanceExportController;
use App\Http\Controllers\Comms\DownloadEngagementExportController;
use App\Http\Controllers\Comms\DownloadRevenueExportController;
use Illuminate\Support\Facades\Route;

Route::get('/parent/dashboard', fn () => view('parent.dashboard'))
    ->name('parent.dashboard');

// No 'auth' middleware — same "no single named login route" reasoning
// MyWishlist's own docblock established (S13 B3); a guest sees a
// log-in prompt instead of a 500.
Route::get('/messages', fn () => view('comms.messages'))
    ->name('messages.index');

Route::get('/exports/attendance-register', DownloadAttendanceExportController::class)
    ->name('comms.attendance-export.download');

Route::get('/exports/revenue-register', DownloadRevenueExportController::class)
    ->name('comms.revenue-export.download');

Route::get('/exports/engagement-register', DownloadEngagementExportController::class)
    ->name('comms.engagement-export.download');
