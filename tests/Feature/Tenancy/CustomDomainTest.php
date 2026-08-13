<?php

use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\CustomDomainService;
use App\Exceptions\TenantNotFoundException;
use App\Filament\Admin\Pages\CustomDomainSettings;
use App\Http\Middleware\ResolveTenant;
use App\Integrations\Drivers\FakeDnsResolver;
use App\Integrations\DnsResolver;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->fakeDns = new FakeDnsResolver;
    $this->app->instance(DnsResolver::class, $this->fakeDns);
});

it('sets a custom domain and resets verification', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);

    app(CustomDomainService::class)->setDomain($tenant, 'learn.theirschool.com');

    expect($tenant->fresh()->custom_domain)->toBe('learn.theirschool.com');
    expect($tenant->fresh()->custom_domain_verified_at)->toBeNull();
});

it('verifies a domain once the expected TXT record is published', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $service = app(CustomDomainService::class);
    $service->setDomain($tenant, 'learn.theirschool.com');

    $this->fakeDns->seed($service->challengeHost($tenant), [$service->verificationToken($tenant)]);

    expect($service->verify($tenant))->toBeTrue();
    expect($tenant->fresh()->custom_domain_verified_at)->not->toBeNull();
    expect($tenant->fresh()->hasVerifiedCustomDomain())->toBeTrue();
});

it('refuses to verify when the TXT record is missing or wrong', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $service = app(CustomDomainService::class);
    $service->setDomain($tenant, 'learn.theirschool.com');

    $this->fakeDns->seed($service->challengeHost($tenant), ['some-other-value']);

    expect($service->verify($tenant))->toBeFalse();
    expect($tenant->fresh()->custom_domain_verified_at)->toBeNull();
});

it('resolves a verified custom domain the same way a subdomain resolves, strict mode', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $service = app(CustomDomainService::class);
    $service->setDomain($tenant, 'learn.theirschool.com');
    $this->fakeDns->seed($service->challengeHost($tenant), [$service->verificationToken($tenant)]);
    $service->verify($tenant);

    $request = Request::create('http://learn.theirschool.com/', 'GET');

    $response = (new ResolveTenant)->handle($request, fn () => new Response('ok'));

    expect($response->getContent())->toBe('ok');
    expect(app(CurrentTenant::class)->id())->toBe($tenant->id);
});

it('resolves nothing for an unverified custom domain, strict mode throws not-found', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CustomDomainService::class)->setDomain($tenant, 'learn.theirschool.com');
    // Deliberately not verified.

    $request = Request::create('http://learn.theirschool.com/', 'GET');

    expect(fn () => (new ResolveTenant)->handle($request, fn () => new Response('ok')))
        ->toThrow(TenantNotFoundException::class);
});

it('resolves nothing for an unrecognised custom domain in optional mode, without throwing', function () {
    $request = Request::create('http://nobody-owns-this.example.com/', 'GET');

    $response = (new ResolveTenant)->handle($request, fn () => new Response('passed through'), 'optional');

    expect($response->getContent())->toBe('passed through');
    expect(app(CurrentTenant::class)->isBound())->toBeFalse();
});

it('sets URL defaults to the tenants own subdomain, not the custom domain host', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $service = app(CustomDomainService::class);
    $service->setDomain($tenant, 'learn.theirschool.com');
    $this->fakeDns->seed($service->challengeHost($tenant), [$service->verificationToken($tenant)]);
    $service->verify($tenant);

    $request = Request::create('http://learn.theirschool.com/', 'GET');
    (new ResolveTenant)->handle($request, fn () => new Response('ok'));

    expect(route('student.dashboard'))->toContain('physics.lms.test');
    expect(route('student.dashboard'))->not->toContain('learn.theirschool.com');
});

it('lets an institute_admin save a domain and verify it from the Filament settings page', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');

    app(CurrentTenant::class)->set($tenant);
    $this->actingAs($admin);

    Livewire::test(CustomDomainSettings::class)
        ->set('data.custom_domain', 'learn.theirschool.com')
        ->callAction('save');

    expect($tenant->fresh()->custom_domain)->toBe('learn.theirschool.com');

    $service = app(CustomDomainService::class);
    $this->fakeDns->seed($service->challengeHost($tenant->fresh()), [$service->verificationToken($tenant->fresh())]);

    Livewire::test(CustomDomainSettings::class)
        ->callAction('verify');

    expect($tenant->fresh()->hasVerifiedCustomDomain())->toBeTrue();
});

it('rejects a custom domain already claimed by another tenant', function () {
    $physics = Tenant::factory()->create(['subdomain' => 'physics']);
    $chemistry = Tenant::factory()->create(['subdomain' => 'chemistry']);
    app(CustomDomainService::class)->setDomain($chemistry, 'learn.shared.com');

    $admin = User::factory()->create(['tenant_id' => $physics->id]);
    $admin->assignRole('institute_admin');

    app(CurrentTenant::class)->set($physics);
    $this->actingAs($admin);

    Livewire::test(CustomDomainSettings::class)
        ->set('data.custom_domain', 'learn.shared.com')
        ->callAction('save')
        ->assertHasFormErrors(['custom_domain']);

    expect($physics->fresh()->custom_domain)->toBeNull();
});
