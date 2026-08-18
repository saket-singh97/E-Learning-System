<?php

use App\Domains\Security\Listeners\ReconcileCookieConsent;
use App\Domains\Security\Services\CookieConsentService;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reconciles the pending session onto the logged-in user, and clears the stash', function () {
    $user = User::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
    $consent = app(CookieConsentService::class)->record('pending-session', null, null, ['necessary' => true]);

    session()->put(CookieConsentService::PENDING_SESSION_KEY, 'pending-session');

    app(ReconcileCookieConsent::class)->handle(new Login('web', $user, false));

    expect($consent->fresh()->user_id)->toBe($user->id);
    expect(session()->has(CookieConsentService::PENDING_SESSION_KEY))->toBeFalse();
});

it('does nothing when there is no pending session stashed', function () {
    $user = User::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);

    expect(fn () => app(ReconcileCookieConsent::class)->handle(new Login('web', $user, false)))
        ->not->toThrow(Throwable::class);
});

/**
 * The real wiring proof — same "one genuine HTTP login, no custom cookie
 * involved" shape `RecordLoginHistoryTest`'s own end-to-end case already
 * established (B3). `withSession()` pre-seeds the pending-session key
 * directly, sidestepping B2's own cookie-encryption saga entirely, since
 * this mechanism never reads a cookie at all — only session data.
 */
it('reconciles a pre-login consent to the real user on an actual HTTP login', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $consent = app(CookieConsentService::class)->record('pre-login-session-abc', null, $tenant->id, ['necessary' => true]);

    $this->withSession([CookieConsentService::PENDING_SESSION_KEY => 'pre-login-session-abc'])
        ->postJson('http://physics.lms.test/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk();

    expect($consent->fresh()->user_id)->toBe($user->id);
});
