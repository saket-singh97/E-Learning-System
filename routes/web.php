<?php

use App\Http\Controllers\Assignments\VerifyCertificateController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Batches\ZoomOAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Host-agnostic (works on both the bare super-panel host and any tenant
// subdomain) and auth-only, matching Livewire's own /livewire/update
// endpoint's own reasoning (CLAUDE.md) — a student, parent, or super_admin
// alike can reach this regardless of which surface they're logged in
// through. Stub until B9 builds a real shared profile page.
Route::middleware('auth')->get('/settings/two-factor', function () {
    return view('auth.two-factor-settings');
})->name('two-factor.settings');

// Host-agnostic on purpose (see GoogleAuthController's own docblock) — the
// redirect_uri Google calls back to must be one fixed, pre-registered URL,
// which rules out a per-tenant subdomain route here.
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])
    ->name('auth.google.callback');

// Host-agnostic on purpose (see ZoomOAuthController's own docblock) — the
// redirect_uri Zoom calls back to must be one fixed, pre-registered URL,
// same constraint GoogleAuthController's own callback already established.
Route::get('/auth/zoom/callback', [ZoomOAuthController::class, 'callback'])
    ->name('zoom.oauth.callback');

// Host-agnostic (see ParentInviteRedemption's own docblock) — a prospective
// parent's invite code, not the subdomain they land on, carries the tenant.
Route::get('/invite', function () {
    return view('auth.parent-invite');
})->name('parent-invite.show');

// Host-agnostic, same reasoning as /invite above — a certificate's own
// verification_id carries the tenant, not whichever subdomain someone
// happens to be browsing from when they scan the QR code (S10 B13, see
// VerifyCertificateController's own docblock).
Route::get('/certificates/verify/{verificationId}', VerifyCertificateController::class)
    ->name('certificates.verify');

Route::domain('{subdomain}.lms.test')
    ->middleware(['web', 'resolve.tenant'])
    ->group(base_path('routes/auth.php'));

Route::domain('{subdomain}.lms.test')
    ->middleware(['web', 'resolve.tenant'])
    ->group(base_path('routes/student.php'));

Route::domain('{subdomain}.lms.test')
    ->middleware(['web', 'resolve.tenant'])
    ->group(base_path('routes/content.php'));

Route::domain('{subdomain}.lms.test')
    ->middleware(['web', 'resolve.tenant'])
    ->group(base_path('routes/assessments.php'));

Route::domain('{subdomain}.lms.test')
    ->middleware(['web', 'resolve.tenant'])
    ->group(base_path('routes/assignments.php'));

Route::domain('{subdomain}.lms.test')
    ->middleware(['web', 'resolve.tenant'])
    ->group(base_path('routes/fees.php'));

Route::domain('{subdomain}.lms.test')
    ->middleware(['web', 'resolve.tenant'])
    ->group(base_path('routes/comms.php'));

Route::domain('{subdomain}.lms.test')
    ->middleware(['web', 'resolve.tenant'])
    ->group(base_path('routes/security.php'));

Route::domain('{subdomain}.lms.test')
    ->middleware(['web', 'resolve.tenant'])
    ->group(base_path('routes/batches.php'));
