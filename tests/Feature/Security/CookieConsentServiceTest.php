<?php

use App\Domains\Security\Models\CookieConsent;
use App\Domains\Security\Services\CookieConsentService;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records a pre-login consent with no user, and stashes the session id for later reconciliation', function () {
    $tenant = Tenant::factory()->create();

    $consent = app(CookieConsentService::class)->record(
        sessionId: 'anon-session-1',
        user: null,
        tenantId: $tenant->id,
        categories: ['necessary' => true, 'analytics' => false, 'marketing' => false],
    );

    expect($consent)->toBeInstanceOf(CookieConsent::class)
        ->and($consent->tenant_id)->toBe($tenant->id)
        ->and($consent->user_id)->toBeNull()
        ->and($consent->session_id)->toBe('anon-session-1')
        ->and($consent->categories)->toMatchArray(['necessary' => true, 'analytics' => false, 'marketing' => false])
        ->and($consent->consented_at)->not->toBeNull();

    expect(session(CookieConsentService::PENDING_SESSION_KEY))->toBe('anon-session-1');
});

it('records a post-login consent with a user directly, no session stash needed', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    $consent = app(CookieConsentService::class)->record(
        sessionId: 'auth-session-1',
        user: $user,
        tenantId: $tenant->id,
        categories: ['necessary' => true, 'analytics' => true, 'marketing' => true],
    );

    expect($consent->user_id)->toBe($user->id);
    expect(session()->has(CookieConsentService::PENDING_SESSION_KEY))->toBeFalse();
});

it('finds a decision by session id for an anonymous visitor', function () {
    app(CookieConsentService::class)->record('anon-2', null, null, ['necessary' => true]);

    expect(app(CookieConsentService::class)->hasDecision('anon-2', null))->toBeTrue();
    expect(app(CookieConsentService::class)->hasDecision('some-other-session', null))->toBeFalse();
});

it('finds a decision by user id once logged in, including a reconciled pre-login row', function () {
    $user = User::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
    $consent = app(CookieConsentService::class)->record('anon-3', null, null, ['necessary' => true]);

    expect(app(CookieConsentService::class)->hasDecision('anon-3', $user))->toBeFalse();

    app(CookieConsentService::class)->reconcile('anon-3', $user);

    expect(app(CookieConsentService::class)->hasDecision('anon-3', $user))->toBeTrue();
    expect($consent->fresh()->user_id)->toBe($user->id);
});

it('reconcile only backfills rows for the given session id, not every unattached row', function () {
    $user = User::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
    $mine = app(CookieConsentService::class)->record('mine', null, null, ['necessary' => true]);
    $someoneElses = app(CookieConsentService::class)->record('not-mine', null, null, ['necessary' => true]);

    app(CookieConsentService::class)->reconcile('mine', $user);

    expect($mine->fresh()->user_id)->toBe($user->id)
        ->and($someoneElses->fresh()->user_id)->toBeNull();
});

it('reconcile never touches a row that already has a user attached', function () {
    $user = User::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
    $otherUser = User::factory()->create(['tenant_id' => $user->tenant_id]);
    $alreadyAttached = app(CookieConsentService::class)->record('shared-session', $otherUser, null, ['necessary' => true]);

    app(CookieConsentService::class)->reconcile('shared-session', $user);

    expect($alreadyAttached->fresh()->user_id)->toBe($otherUser->id);
});
