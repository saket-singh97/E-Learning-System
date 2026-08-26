<?php

use App\Http\Controllers\Planner\DownloadCalendarFeedController;
use Illuminate\Support\Facades\Route;

// S19 N12 — no 'auth' middleware, same "no single named login route"
// reasoning MyWishlist's own docblock established (S13 B3); a guest sees a
// log-in prompt inside the component rather than a 500.
Route::get('/calendar', fn () => view('planner.calendar'))
    ->name('calendar.show');

// S19 N12 pt.3 — signed URL only, no auth middleware: an external calendar
// app has no session to carry, the signature itself is the access token
// (design doc §4 decision 4).
Route::get('/calendar/{user}/feed.ics', DownloadCalendarFeedController::class)
    ->middleware('signed')
    ->name('calendar.feed');
