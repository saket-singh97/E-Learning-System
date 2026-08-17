<?php

use App\Domains\Security\Models\DeviceSession;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/**
 * S12 B1 — proves the actual wiring, not just the service in isolation:
 * a real login through the real JSON endpoint fires
 * Illuminate\Auth\Events\Login, which RegisterDeviceOnLogin picks up.
 * The Filament-render-hook lesson from S9 B9 applies here too — "the
 * listener class exists and looks right" is not the same claim as "the
 * event actually reaches it," so this is tested end to end through a
 * real HTTP request, not Livewire::test() or a direct service call.
 */
it('creates a device_sessions row on a real login through the JSON endpoint', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    $this->withUnencryptedCookie('lms_device_id', 'integration-device-a')
        ->postJson('http://physics.lms.test/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
        ->assertOk();

    expect(DeviceSession::withoutGlobalScope('tenant')->where('user_id', $user->id)->count())->toBe(1);
});

it('refuses a login from a device beyond the configured limit, and leaves the user logged out', function () {
    config(['security.device_limit' => 2]);
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    $this->withUnencryptedCookie('lms_device_id', 'integration-device-a')
        ->postJson('http://physics.lms.test/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk();
    Auth::logout();

    $this->withUnencryptedCookie('lms_device_id', 'integration-device-b')
        ->postJson('http://physics.lms.test/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk();
    Auth::logout();

    $this->withUnencryptedCookie('lms_device_id', 'integration-device-c')
        ->postJson('http://physics.lms.test/login', ['email' => $user->email, 'password' => 'password'])
        ->assertStatus(409);

    $this->assertGuest();
    expect(DeviceSession::withoutGlobalScope('tenant')->where('user_id', $user->id)->count())->toBe(2);
});
