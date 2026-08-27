<?php

use Illuminate\Support\Facades\Route;

// S20 R2 — no 'auth' middleware, same "guest sees a log-in prompt inside
// the component" reasoning Planner's own calendar route already
// established; a guest sees a log-in prompt rather than a 500.
Route::get('/affiliate', fn () => view('growth.affiliate-dashboard'))
    ->name('affiliate.dashboard');
