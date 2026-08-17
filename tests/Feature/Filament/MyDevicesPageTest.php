<?php

use App\Domains\Security\Models\DeviceSession;
use App\Domains\Tenancy\Models\Tenant;
use App\Filament\Admin\Pages\MyDevices as AdminMyDevices;
use App\Filament\Teach\Pages\MyDevices as TeachMyDevices;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('lists only the current users own devices, not another users or another tenants', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');
    $otherUser = User::factory()->create(['tenant_id' => $tenant->id]);

    $mine = DeviceSession::withoutEvents(fn () => DeviceSession::create([
        'tenant_id' => $tenant->id,
        'user_id' => $admin->id,
        'device_fingerprint' => 'fp-mine',
        'last_seen_at' => now(),
    ]));
    $notMine = DeviceSession::withoutEvents(fn () => DeviceSession::create([
        'tenant_id' => $tenant->id,
        'user_id' => $otherUser->id,
        'device_fingerprint' => 'fp-other-user',
        'last_seen_at' => now(),
    ]));

    $this->actingAs($admin);
    $this->get('http://physics.lms.test/admin/my-devices')->assertOk();

    Livewire::test(AdminMyDevices::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$notMine]);
});

/**
 * "Current device" hiding (`MyDevices::table()`'s own `is_current`
 * comparison) is not covered by an automated test — getting a custom app
 * cookie to survive Laravel's test HTTP client with a known, stable value
 * turned out to be genuinely unreliable in this Laravel version (neither
 * `withCookie()` nor `withUnencryptedCookie()` produced a fingerprint
 * match, across several attempts, for reasons not fully root-caused; see
 * `EnsureDeviceNotRevokedTest`'s own notes and the plan doc's B2 section).
 * A real limitation of this test suite, flagged rather than papered over
 * with a test that would pass for the wrong reason — the underlying
 * comparison itself (`$record->device_fingerprint === $currentFingerprint`)
 * is a one-line equality check with no independent logic worth a
 * lower-level unit test on its own.
 */
it('revokes another device via the table action', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');

    $device = DeviceSession::withoutEvents(fn () => DeviceSession::create([
        'tenant_id' => $tenant->id,
        'user_id' => $admin->id,
        'device_fingerprint' => 'device-to-revoke',
        'last_seen_at' => now(),
    ]));

    $this->actingAs($admin);

    Livewire::test(AdminMyDevices::class)
        ->callTableAction('revoke', $device);

    expect($device->fresh()->revoked_at)->not->toBeNull();
});

it('shows the same devices on the Teach panels own copy of the page', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $instructor->assignRole('instructor');

    $device = DeviceSession::withoutEvents(fn () => DeviceSession::create([
        'tenant_id' => $tenant->id,
        'user_id' => $instructor->id,
        'device_fingerprint' => 'fp-teach',
        'last_seen_at' => now(),
    ]));

    $this->actingAs($instructor);

    Livewire::test(TeachMyDevices::class)
        ->assertCanSeeTableRecords([$device]);
});
