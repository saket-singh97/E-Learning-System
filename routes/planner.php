<?php

use Illuminate\Support\Facades\Route;

// S19 N12 — no 'auth' middleware, same "no single named login route"
// reasoning MyWishlist's own docblock established (S13 B3); a guest sees a
// log-in prompt inside the component rather than a 500.
Route::get('/calendar', fn () => view('planner.calendar'))
    ->name('calendar.show');
