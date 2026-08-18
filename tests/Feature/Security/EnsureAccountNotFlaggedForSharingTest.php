<?php

use App\Domains\Security\Models\AccountSharingFlag;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Middleware\EnsureAccountNotFlaggedForSharing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

/**
 * Unlike `EnsureDeviceNotRevoked` (B2), this middleware never reads a
 * cookie — only `Auth::user()` and a DB lookup — so none of B2's own
 * `EncryptCookies` round-trip pitfalls apply. Still calls `handle()`
 * directly for the logic-focused cases (full control, no framework
 * plumbing to second-guess), matching the pattern this whole slice has
 * settled on.
 */
function requestWithSession(): Request
{
    $request = Request::create('/', 'GET');
    $request->setLaravelSession(app('session.store'));

    return $request;
}

it('blocks a request for a user with an unreviewed flag, tears the session down, and marks it reviewed', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $this->actingAs($user);

    $flag = AccountSharingFlag::withoutEvents(fn () => AccountSharingFlag::create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'distinct_device_count_7d' => 5,
        'flagged_at' => now(),
    ]));

    expect(fn () => (new EnsureAccountNotFlaggedForSharing)->handle(requestWithSession(), fn ($req) => response('passed')))
        ->toThrow(HttpException::class);

    expect(Auth::check())->toBeFalse()
        ->and($flag->fresh()->reviewed_at)->not->toBeNull();
});

it('lets a request through once the flag is already reviewed', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $this->actingAs($user);

    AccountSharingFlag::withoutEvents(fn () => AccountSharingFlag::create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'distinct_device_count_7d' => 5,
        'flagged_at' => now(),
        'reviewed_at' => now(),
    ]));

    $response = (new EnsureAccountNotFlaggedForSharing)->handle(requestWithSession(), fn ($req) => response('passed'));

    expect($response->getContent())->toBe('passed')
        ->and(Auth::check())->toBeTrue();
});

it('only forces one logout per flag, not a loop, once the user logs back in', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $this->actingAs($user);

    AccountSharingFlag::withoutEvents(fn () => AccountSharingFlag::create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'distinct_device_count_7d' => 5,
        'flagged_at' => now(),
    ]));

    expect(fn () => (new EnsureAccountNotFlaggedForSharing)->handle(requestWithSession(), fn ($req) => response('passed')))
        ->toThrow(HttpException::class);

    // A real login re-establishes the session (mirrors what would happen
    // in production between these two calls).
    $this->actingAs($user);

    $response = (new EnsureAccountNotFlaggedForSharing)->handle(requestWithSession(), fn ($req) => response('passed'));

    expect($response->getContent())->toBe('passed');
});

it('lets a request through when the user has no flag at all', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $this->actingAs($user);

    $response = (new EnsureAccountNotFlaggedForSharing)->handle(requestWithSession(), fn ($req) => response('passed'));

    expect($response->getContent())->toBe('passed');
});

it('never blocks a platform-level user with no tenant', function () {
    $superAdmin = User::factory()->create(['tenant_id' => null]);
    $this->actingAs($superAdmin);

    $response = (new EnsureAccountNotFlaggedForSharing)->handle(requestWithSession(), fn ($req) => response('passed'));

    expect($response->getContent())->toBe('passed');
});

it('lets a guest request through with no authenticated user to check', function () {
    $response = (new EnsureAccountNotFlaggedForSharing)->handle(requestWithSession(), fn ($req) => response('passed'));

    expect($response->getContent())->toBe('passed');
});

/**
 * The real wiring proof — no custom cookie involved, so a genuine HTTP
 * request is safe here (unlike B2's own device-fingerprint saga).
 */
it('blocks a real HTTP request end to end for a flagged user', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    $this->postJson('http://physics.lms.test/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk();

    AccountSharingFlag::withoutEvents(fn () => AccountSharingFlag::create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'distinct_device_count_7d' => 5,
        'flagged_at' => now(),
    ]));

    $this->postJson('http://physics.lms.test/logout')->assertStatus(401);

    $this->assertGuest();
});
