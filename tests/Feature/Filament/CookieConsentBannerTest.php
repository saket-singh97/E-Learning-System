<?php

use App\Domains\Security\Models\CookieConsent;
use App\Domains\Tenancy\Models\Tenant;
use App\Livewire\CookieConsentBanner;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('shows the banner when no decision has been made yet', function () {
    Livewire::test(CookieConsentBanner::class)
        ->assertSee('Accept all');
});

it('records all categories accepted, tied to the current session, and hides itself', function () {
    Livewire::test(CookieConsentBanner::class)
        ->call('acceptAll')
        ->assertDontSee('Accept all');

    $consent = CookieConsent::sole();
    expect($consent->categories)->toMatchArray(['necessary' => true, 'analytics' => true, 'marketing' => true])
        ->and($consent->user_id)->toBeNull();
});

it('records necessary-only, and hides itself', function () {
    Livewire::test(CookieConsentBanner::class)
        ->call('acceptNecessaryOnly')
        ->assertDontSee('Accept all');

    $consent = CookieConsent::sole();
    expect($consent->categories)->toMatchArray(['necessary' => true, 'analytics' => false, 'marketing' => false]);
});

it('records the bound tenant id when one is bound, null otherwise', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    Livewire::test(CookieConsentBanner::class)->call('acceptAll');

    expect(CookieConsent::sole()->tenant_id)->toBe($tenant->id);
});

it('attaches the current user directly when already logged in', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user);

    Livewire::test(CookieConsentBanner::class)->call('acceptAll');

    expect(CookieConsent::sole()->user_id)->toBe($user->id);
});

it('is visible on the real guest login page, before any login', function () {
    Tenant::factory()->create(['subdomain' => 'physics']);

    $this->get('http://physics.lms.test/admin/login')
        ->assertOk()
        ->assertSee('Accept all');
});

it('is visible on a real authenticated admin panel page', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');

    $this->actingAs($admin)
        ->get('http://physics.lms.test/admin')
        ->assertOk()
        ->assertSee('Accept all');
});

it('stays hidden for a user whose pre-login consent was already reconciled', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    CookieConsent::create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'session_id' => 'irrelevant',
        'categories' => ['necessary' => true],
        'consented_at' => now(),
    ]);

    $this->actingAs($user);

    Livewire::test(CookieConsentBanner::class)
        ->assertDontSee('Accept all');
});
