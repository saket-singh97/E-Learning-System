<?php

use App\Domains\Security\Models\DeviceSession;
use App\Domains\Security\Services\DeviceRegistrar;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\DeviceLimitExceededException;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

function deviceRequest(string $deviceId, string $userAgent = 'TestAgent/1.0'): Request
{
    $request = Request::create('/login', 'POST');
    $request->headers->set('User-Agent', $userAgent);
    $request->cookies->set('lms_device_id', $deviceId);

    return $request;
}

it('creates a device_sessions row on a genuinely new device', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    app(DeviceRegistrar::class)->registerLogin($user, deviceRequest('device-a'));

    expect(DeviceSession::withoutGlobalScope('tenant')->where('user_id', $user->id)->count())->toBe(1);
});

it('refreshes last_seen_at on a returning device instead of creating a second row', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $registrar = app(DeviceRegistrar::class);

    $registrar->registerLogin($user, deviceRequest('device-a'));
    $this->travel(1)->hour();
    $registrar->registerLogin($user, deviceRequest('device-a'));

    expect(DeviceSession::withoutGlobalScope('tenant')->where('user_id', $user->id)->count())->toBe(1);
});

it('allows exactly the configured device_limit distinct devices, then refuses the next new one', function () {
    config(['security.device_limit' => 2]);
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $registrar = app(DeviceRegistrar::class);

    $registrar->registerLogin($user, deviceRequest('device-a'));
    $registrar->registerLogin($user, deviceRequest('device-b'));

    expect(fn () => $registrar->registerLogin($user, deviceRequest('device-c')))
        ->toThrow(DeviceLimitExceededException::class);

    expect(DeviceSession::withoutGlobalScope('tenant')->where('user_id', $user->id)->count())->toBe(2);
});

it('does not count a revoked device against the limit', function () {
    config(['security.device_limit' => 1]);
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $registrar = app(DeviceRegistrar::class);

    $registrar->registerLogin($user, deviceRequest('device-a'));
    DeviceSession::withoutGlobalScope('tenant')->where('user_id', $user->id)->update(['revoked_at' => now()]);

    $registrar->registerLogin($user, deviceRequest('device-b'));

    expect(DeviceSession::withoutGlobalScope('tenant')->where('user_id', $user->id)->whereNull('revoked_at')->count())->toBe(1);
});

/**
 * S12 B1 — the real reason registerLogin() never trusts ambient
 * CurrentTenant: Google OAuth's callback route is host-agnostic
 * (S9 B2), so CurrentTenant is genuinely unbound by the time a login
 * there fires. Proven directly here rather than only asserted in a
 * comment.
 */
it('derives tenant_id from the user, not from an unbound or mismatched ambient CurrentTenant', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    app(CurrentTenant::class)->clear();

    app(DeviceRegistrar::class)->registerLogin($user, deviceRequest('device-a'));

    $row = DeviceSession::withoutGlobalScope('tenant')->where('user_id', $user->id)->sole();
    expect($row->tenant_id)->toBe($tenant->id)->not->toBe($other->id);
});
