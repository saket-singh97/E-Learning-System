<?php

use App\Domains\Tenancy\Models\Tenant;
use App\Filament\Admin\Auth\Login as AdminLogin;
use App\Filament\Super\Auth\Login as SuperLogin;
use App\Filament\Teach\Auth\Login as TeachLogin;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('logs an instructor into the teach panel through a real login form, not actingAs', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $instructor->assignRole('instructor');

    $this->get('http://physics.lms.test/teach/login')->assertOk();

    Livewire::test(TeachLogin::class)
        ->set('data.email', $instructor->email)
        ->set('data.password', 'password')
        ->call('authenticate');

    $this->assertAuthenticatedAs($instructor);
});

it('logs an institute admin into the admin panel through a real login form', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');

    $this->get('http://physics.lms.test/admin/login')->assertOk();

    Livewire::test(AdminLogin::class)
        ->set('data.email', $admin->email)
        ->set('data.password', 'password')
        ->call('authenticate');

    $this->assertAuthenticatedAs($admin);
});

it('logs a super admin into the super panel through a real login form, no subdomain needed', function () {
    $superAdmin = User::factory()->create(['tenant_id' => null]);
    $superAdmin->assignRole('super_admin');

    $this->get('http://lms.test/super/login')->assertOk();

    Livewire::test(SuperLogin::class)
        ->set('data.email', $superAdmin->email)
        ->set('data.password', 'password')
        ->call('authenticate');

    $this->assertAuthenticatedAs($superAdmin);
});

it('only authenticates the user belonging to the tenant on the current subdomain, same email in both', function () {
    $physics = Tenant::factory()->create(['subdomain' => 'physics']);
    $chemistry = Tenant::factory()->create(['subdomain' => 'chemistry']);

    $physicsInstructor = User::factory()->create(['tenant_id' => $physics->id, 'email' => 'shared@example.com']);
    $physicsInstructor->assignRole('instructor');

    $chemistryInstructor = User::factory()->create(['tenant_id' => $chemistry->id, 'email' => 'shared@example.com']);
    $chemistryInstructor->assignRole('instructor');

    $this->get('http://physics.lms.test/teach/login')->assertOk();

    Livewire::test(TeachLogin::class)
        ->set('data.email', 'shared@example.com')
        ->set('data.password', 'password')
        ->call('authenticate');

    $this->assertAuthenticatedAs($physicsInstructor);
    expect(auth()->id())->not->toBe($chemistryInstructor->id);
});

it('locks the account after 5 failed attempts through the panel login, same lockout as the JSON endpoint', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $instructor->assignRole('instructor');

    $this->get('http://physics.lms.test/teach/login')->assertOk();

    for ($i = 0; $i < 5; $i++) {
        Livewire::test(TeachLogin::class)
            ->set('data.email', $instructor->email)
            ->set('data.password', 'wrong-password')
            ->call('authenticate')
            ->assertHasFormErrors();
    }

    Livewire::test(TeachLogin::class)
        ->set('data.email', $instructor->email)
        ->set('data.password', 'password')
        ->call('authenticate')
        ->assertHasFormErrors();

    $this->assertGuest();

    RateLimiter::clear('login-lockout:'.$instructor->email.'|127.0.0.1');
});

it('challenges an instructor with 2FA enabled through Filaments own multi-factor step before logging in', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $instructor->assignRole('instructor');

    $appAuthentication = app(AppAuthentication::class);
    $secret = $appAuthentication->generateSecret();
    $appAuthentication->saveSecret($instructor, $secret);
    $appAuthentication->saveRecoveryCodes($instructor, $appAuthentication->generateRecoveryCodes());

    $this->get('http://physics.lms.test/teach/login')->assertOk();

    $component = Livewire::test(TeachLogin::class)
        ->set('data.email', $instructor->email)
        ->set('data.password', 'password')
        ->call('authenticate');

    $this->assertGuest();

    $code = $appAuthentication->getCurrentCode($instructor, $secret);

    $component
        ->set('data.multiFactor.app.code', $code)
        ->call('authenticate');

    $this->assertAuthenticatedAs($instructor);
});
