<?php

use App\Http\Controllers\Batches\DeviceWebhookController;
use App\Http\Controllers\Batches\ZoomRecordingWebhookController;
use App\Http\Controllers\Billing\RazorpaySubscriptionWebhookController;
use App\Http\Controllers\Fees\RazorpayWebhookController;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// S21 B4 (V12b/T5) — the first real `/api/v1/` route (FEATURES.md's own T1
// naming): an institute's own external system calls this with its own
// api_keys bearer token to confirm the key is valid and which tenant it
// resolves to. The 4 vendor webhooks below stay untouched — each already
// authenticates via its own vendor-specific signature scheme, and a vendor
// has no way to supply an institute's bearer token, so bearer auth is
// additive here, not retrofitted onto routes it would break.
Route::get('/v1/health', function () {
    return response()->json([
        'ok' => true,
        'tenant' => app(CurrentTenant::class)->get()->subdomain,
    ]);
})->middleware('api.key')->name('api.v1.health');

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

// S16 B4 (H12) — per-credential secret, not one fixed app-wide one, so
// the route carries the credential id; the controller looks it up (no
// tenant scope bound yet, same reasoning as the Zoom route above) before
// checking its signature.
Route::post('/webhooks/device/{credential}', DeviceWebhookController::class)
    ->name('webhooks.device');
