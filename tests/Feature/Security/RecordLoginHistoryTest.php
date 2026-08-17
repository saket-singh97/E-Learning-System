<?php

use App\Domains\Security\Listeners\RecordLoginHistory;
use App\Domains\Security\Models\LoginHistory;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

/**
 * `REMOTE_ADDR`, not a cookie — Laravel's `Request::ip()` reads it
 * directly off the server bag, no `EncryptCookies` pass involved at all,
 * so (unlike B2's own device-fingerprint cookie saga) there's no
 * encryption round-trip to fight here. Still binds the request directly
 * into the container (`app()->instance('request', ...)`) rather than
 * going through a real HTTP dispatch, for the same reason B2 ultimately
 * settled on calling middleware directly: full control, zero framework
 * plumbing to second-guess.
 */
function bindLoginRequestFromIp(string $ip): void
{
    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => $ip]);
    $request->headers->set('User-Agent', 'TestAgent/1.0');

    app()->instance('request', $request);
}

it('records a login_history row with geo data resolved from the ip', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    bindLoginRequestFromIp('203.0.113.10');
    app(RecordLoginHistory::class)->handle(new Login('web', $user, false));

    $row = LoginHistory::withoutGlobalScope('tenant')->where('user_id', $user->id)->sole();
    expect($row->ip_address)->toBe('203.0.113.10')
        ->and($row->country)->toBe('IN')
        ->and($row->city)->toBe('Mumbai')
        ->and($row->successful)->toBeTrue()
        ->and($row->flagged_impossible_travel)->toBeFalse();
});

it('does not flag the very first login for a user, nothing to compare against', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    bindLoginRequestFromIp('203.0.113.20');
    app(RecordLoginHistory::class)->handle(new Login('web', $user, false));

    expect(LoginHistory::withoutGlobalScope('tenant')->where('user_id', $user->id)->sole()->flagged_impossible_travel)->toBeFalse();
});

it('flags a login that is geographically impossible given the immediately preceding one', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    bindLoginRequestFromIp('203.0.113.10'); // Mumbai
    app(RecordLoginHistory::class)->handle(new Login('web', $user, false));

    $this->travel(5)->minutes();

    bindLoginRequestFromIp('203.0.113.20'); // New York, 5 minutes later
    app(RecordLoginHistory::class)->handle(new Login('web', $user, false));

    $rows = LoginHistory::withoutGlobalScope('tenant')->where('user_id', $user->id)->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->flagged_impossible_travel)->toBeFalse()
        ->and($rows[1]->flagged_impossible_travel)->toBeTrue();
});

it('does not flag two logins from nearby locations within a plausible timeframe', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    bindLoginRequestFromIp('203.0.113.10'); // Mumbai
    app(RecordLoginHistory::class)->handle(new Login('web', $user, false));

    $this->travel(2)->hours();

    bindLoginRequestFromIp('203.0.113.40'); // Pune, ~150 km away
    app(RecordLoginHistory::class)->handle(new Login('web', $user, false));

    $latest = LoginHistory::withoutGlobalScope('tenant')->where('user_id', $user->id)->latest('id')->first();
    expect($latest->flagged_impossible_travel)->toBeFalse();
});

it('never records history for a platform-level user with no tenant', function () {
    $superAdmin = User::factory()->create(['tenant_id' => null]);

    bindLoginRequestFromIp('203.0.113.10');
    app(RecordLoginHistory::class)->handle(new Login('web', $superAdmin, false));

    expect(LoginHistory::withoutGlobalScope('tenant')->where('user_id', $superAdmin->id)->exists())->toBeFalse();
});

/**
 * The real wiring proof — a genuine HTTP login, same `Illuminate\Auth\Events\Login`
 * dispatch B1's own `RegisterDeviceOnLoginTest` already proves fires
 * correctly on every real login surface; this only needs to confirm
 * `RecordLoginHistory` is actually registered as a second listener on it.
 * No custom cookie involved (IP comes from the test client's own default
 * `REMOTE_ADDR`), so none of B2's own encryption pitfalls apply here.
 */
it('creates a real login_history row on an actual HTTP login', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    $this->postJson('http://physics.lms.test/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk();

    expect(LoginHistory::withoutGlobalScope('tenant')->where('user_id', $user->id)->exists())->toBeTrue();
});
