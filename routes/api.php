<?php

use App\Http\Controllers\Batches\ZoomRecordingWebhookController;
use App\Http\Controllers\Billing\RazorpaySubscriptionWebhookController;
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

// S11 B7 — a separate URL/webhook secret from the Fees one above, since
// Razorpay's Subscriptions API events (subscription.charged, etc.) are a
// different vendor surface from the Orders-API events the route above
// handles.
Route::post('/webhooks/razorpay-subscriptions', RazorpaySubscriptionWebhookController::class)
    ->name('webhooks.razorpay-subscriptions');

// S14 B8 — same "one fixed URL, no tenant subdomain" reasoning as the
// Razorpay webhooks above; the tenant is resolved from the matching
// BatchSession inside the handler, not the route.
Route::post('/webhooks/zoom-recording', ZoomRecordingWebhookController::class)
    ->name('webhooks.zoom-recording');
