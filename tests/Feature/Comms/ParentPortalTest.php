<?php

use App\Domains\Tenancy\Models\Tenant;
use App\Livewire\ParentPortal;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function parentTenantUrl(string $subdomain, string $path): string
{
    return "http://{$subdomain}.lms.test{$path}";
}

it('shows the parent dashboard shell to a guest', function () {
    Tenant::factory()->create(['subdomain' => 'physics']);

    $this->get(parentTenantUrl('physics', '/parent/dashboard'))
        ->assertOk()
        ->assertSeeLivewire(ParentPortal::class);
});

it('logs a parent in and shows the dashboard placeholder', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'password' => bcrypt('secret-password'),
    ]);
    $user->assignRole('parent');

    Livewire::test(ParentPortal::class)
        ->set('email', $user->email)
        ->set('password', 'secret-password')
        ->call('login')
        ->assertSee($user->name);

    $this->assertAuthenticatedAs($user);
});

it('shows an error and stays logged out on wrong credentials', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole('parent');

    Livewire::test(ParentPortal::class)
        ->set('email', $user->email)
        ->set('password', 'wrong-password')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
});

it('locks out after repeated failed attempts, same as every other login surface', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'password' => bcrypt('secret-password'),
    ]);

    for ($i = 0; $i < 5; $i++) {
        Livewire::test(ParentPortal::class)
            ->set('email', $user->email)
            ->set('password', 'wrong-password')
            ->call('login');
    }

    Livewire::test(ParentPortal::class)
        ->set('email', $user->email)
        ->set('password', 'secret-password')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
});

it('regenerates the session on login and invalidates it on logout', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'password' => bcrypt('secret-password'),
    ]);

    session()->start();
    $preLoginId = session()->getId();

    Livewire::test(ParentPortal::class)
        ->set('email', $user->email)
        ->set('password', 'secret-password')
        ->call('login');

    expect(session()->getId())->not->toBe($preLoginId);

    $preLogoutId = session()->getId();

    Livewire::test(ParentPortal::class)
        ->call('logout');

    expect(session()->getId())->not->toBe($preLogoutId);
    $this->assertGuest();
});

it('logs out on mount a session whose own tenant does not match the resolved host', function () {
    // SESSION_DOMAIN is a shared parent domain, so a parent logged in at
    // tenant A stays authenticated if they simply open tenant B's
    // /parent/dashboard URL. Without this check, ParentPortal would render
    // tenant A's parent as "logged in" under tenant B's resolved
    // CurrentTenant — the same class of gap EnsureRole/
    // DownloadInvoiceController close by hand (S7 B7).
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $parent = User::factory()->create(['tenant_id' => $tenantA->id]);
    $parent->assignRole('parent');

    app(CurrentTenant::class)->set($tenantA);
    $this->actingAs($parent);

    app(CurrentTenant::class)->set($tenantB);

    Livewire::test(ParentPortal::class);

    $this->assertGuest();
});
