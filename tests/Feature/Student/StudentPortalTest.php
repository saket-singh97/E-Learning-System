<?php

use App\Domains\Tenancy\Models\Tenant;
use App\Livewire\StudentPortal;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function studentTenantUrl(string $subdomain, string $path): string
{
    return "http://{$subdomain}.lms.test{$path}";
}

it('shows the student dashboard shell to a guest', function () {
    Tenant::factory()->create(['subdomain' => 'physics']);

    $this->get(studentTenantUrl('physics', '/dashboard'))
        ->assertOk()
        ->assertSeeLivewire(StudentPortal::class);
});

it('logs a student in and shows the dashboard placeholder', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'password' => bcrypt('secret-password'),
    ]);

    Livewire::test(StudentPortal::class)
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

    Livewire::test(StudentPortal::class)
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
        Livewire::test(StudentPortal::class)
            ->set('email', $user->email)
            ->set('password', 'wrong-password')
            ->call('login');
    }

    Livewire::test(StudentPortal::class)
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

    Livewire::test(StudentPortal::class)
        ->set('email', $user->email)
        ->set('password', 'secret-password')
        ->call('login');

    expect(session()->getId())->not->toBe($preLoginId);

    $preLogoutId = session()->getId();

    Livewire::test(StudentPortal::class)
        ->call('logout');

    expect(session()->getId())->not->toBe($preLogoutId);
    $this->assertGuest();
});

it('logs out on mount a session whose own tenant does not match the resolved host', function () {
    // SESSION_DOMAIN is a shared parent domain, so a student logged in at
    // tenant A stays authenticated if they simply open tenant B's /dashboard
    // URL. Same gap ParentPortal::mount() already closes (S8 B1) — this
    // portal never had the check at all (S9 B0).
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $student = User::factory()->create(['tenant_id' => $tenantA->id]);

    app(CurrentTenant::class)->set($tenantA);
    $this->actingAs($student);

    app(CurrentTenant::class)->set($tenantB);

    Livewire::test(StudentPortal::class);

    $this->assertGuest();
});
