<?php

use App\Http\Controllers\Fees\RazorpayWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// No Route::domain(...) constraint — Razorpay calls one fixed URL with no
// notion of this app's tenant subdomains (S7 design doc §2.4 decision 6).
// The `api` middleware group excludes CSRF verification by default, which
// is exactly what a vendor webhook needs.
Route::post('/webhooks/razorpay', RazorpayWebhookController::class)
    ->name('webhooks.razorpay');
