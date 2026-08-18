<?php

use App\Domains\Security\Jobs\DetectAccountSharing;
use App\Domains\Security\Models\AccountSharingFlag;
use App\Domains\Security\Models\DeviceSession;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createDeviceSession(Tenant $tenant, User $user, string $fingerprint, ?\Illuminate\Support\Carbon $createdAt = null): DeviceSession
{
    $row = DeviceSession::withoutEvents(fn () => DeviceSession::create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'device_fingerprint' => $fingerprint,
        'last_seen_at' => $createdAt ?? now(),
    ]));

    if ($createdAt !== null) {
        $row->forceFill(['created_at' => $createdAt])->save();
    }

    return $row;
}

it('flags a user with at least the configured threshold of distinct devices in the last 7 days', function () {
    config(['security.account_sharing_device_threshold' => 4]);
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    foreach (['device-a', 'device-b', 'device-c', 'device-d'] as $fp) {
        createDeviceSession($tenant, $user, $fp);
    }

    (new DetectAccountSharing)->handle();

    $flag = AccountSharingFlag::withoutGlobalScope('tenant')->where('user_id', $user->id)->sole();
    expect($flag->tenant_id)->toBe($tenant->id)
        ->and($flag->distinct_device_count_7d)->toBe(4)
        ->and($flag->reviewed_at)->toBeNull();
});

it('does not flag a user below the configured threshold', function () {
    config(['security.account_sharing_device_threshold' => 4]);
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    foreach (['device-a', 'device-b', 'device-c'] as $fp) {
        createDeviceSession($tenant, $user, $fp);
    }

    (new DetectAccountSharing)->handle();

    expect(AccountSharingFlag::withoutGlobalScope('tenant')->where('user_id', $user->id)->exists())->toBeFalse();
});

it('ignores devices first seen outside the rolling 7-day window', function () {
    config(['security.account_sharing_device_threshold' => 4]);
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    createDeviceSession($tenant, $user, 'device-old', now()->subDays(10));
    foreach (['device-a', 'device-b', 'device-c'] as $fp) {
        createDeviceSession($tenant, $user, $fp);
    }

    (new DetectAccountSharing)->handle();

    expect(AccountSharingFlag::withoutGlobalScope('tenant')->where('user_id', $user->id)->exists())->toBeFalse();
});

it('still counts a revoked device toward the threshold, only current-session enforcement excludes it', function () {
    config(['security.account_sharing_device_threshold' => 4]);
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    $revoked = createDeviceSession($tenant, $user, 'device-a');
    $revoked->update(['revoked_at' => now()]);
    foreach (['device-b', 'device-c', 'device-d'] as $fp) {
        createDeviceSession($tenant, $user, $fp);
    }

    (new DetectAccountSharing)->handle();

    expect(AccountSharingFlag::withoutGlobalScope('tenant')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('does not create a duplicate flag while an existing one is unreviewed', function () {
    config(['security.account_sharing_device_threshold' => 4]);
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    foreach (['device-a', 'device-b', 'device-c', 'device-d'] as $fp) {
        createDeviceSession($tenant, $user, $fp);
    }

    (new DetectAccountSharing)->handle();
    (new DetectAccountSharing)->handle();

    expect(AccountSharingFlag::withoutGlobalScope('tenant')->where('user_id', $user->id)->count())->toBe(1);
});

it('flags a new pattern again once the previous flag has been reviewed', function () {
    config(['security.account_sharing_device_threshold' => 4]);
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    foreach (['device-a', 'device-b', 'device-c', 'device-d'] as $fp) {
        createDeviceSession($tenant, $user, $fp);
    }
    (new DetectAccountSharing)->handle();

    AccountSharingFlag::withoutGlobalScope('tenant')->where('user_id', $user->id)->sole()->update(['reviewed_at' => now()]);

    foreach (['device-e', 'device-f'] as $fp) {
        createDeviceSession($tenant, $user, $fp);
    }
    (new DetectAccountSharing)->handle();

    expect(AccountSharingFlag::withoutGlobalScope('tenant')->where('user_id', $user->id)->count())->toBe(2);
});

it('sweeps every tenant in one unscoped pass, regardless of CurrentTenant', function () {
    config(['security.account_sharing_device_threshold' => 4]);
    $tenantA = Tenant::factory()->create();
    $userA = User::factory()->create(['tenant_id' => $tenantA->id]);
    $tenantB = Tenant::factory()->create();
    $userB = User::factory()->create(['tenant_id' => $tenantB->id]);

    foreach (['device-a', 'device-b', 'device-c', 'device-d'] as $fp) {
        createDeviceSession($tenantA, $userA, $fp);
        createDeviceSession($tenantB, $userB, $fp.'-b');
    }

    $unrelated = Tenant::factory()->create();
    app(CurrentTenant::class)->set($unrelated);

    (new DetectAccountSharing)->handle();

    expect(AccountSharingFlag::withoutGlobalScope('tenant')->where('user_id', $userA->id)->exists())->toBeTrue()
        ->and(AccountSharingFlag::withoutGlobalScope('tenant')->where('user_id', $userB->id)->exists())->toBeTrue();
});
