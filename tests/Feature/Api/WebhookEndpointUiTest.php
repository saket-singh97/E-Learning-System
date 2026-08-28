<?php

use App\Domains\Api\Filament\Resources\WebhookEndpoints\Pages\EditWebhookEndpoint;
use App\Domains\Api\Filament\Resources\WebhookEndpoints\Pages\ListWebhookEndpoints;
use App\Domains\Api\Filament\Resources\WebhookEndpoints\RelationManagers\DeliveriesRelationManager;
use App\Domains\Api\Filament\Resources\WebhookEndpoints\WebhookEndpointResource;
use App\Domains\Api\Models\WebhookDelivery;
use App\Domains\Api\Models\WebhookEndpoint;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function webhookAdmin(): array
{
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');

    return [$tenant, $admin];
}

it('registers an endpoint and shows the secret once', function () {
    [$tenant, $admin] = webhookAdmin();

    $this->actingAs($admin)
        ->get(WebhookEndpointResource::getUrl('index', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    Livewire::test(ListWebhookEndpoints::class)
        ->callAction('register', data: ['url' => 'https://institute.example.com/hook']);

    $endpoint = WebhookEndpoint::where('tenant_id', $tenant->id)->sole();
    expect($endpoint->url)->toBe('https://institute.example.com/hook')
        ->and($endpoint->isActive())->toBeTrue()
        ->and($endpoint->secret)->not->toBeEmpty();
});

it('disables and re-enables an endpoint via row actions', function () {
    [$tenant, $admin] = webhookAdmin();
    $endpoint = WebhookEndpoint::create(['url' => 'https://institute.example.com/hook', 'secret' => 'x']);

    $this->actingAs($admin)
        ->get(WebhookEndpointResource::getUrl('index', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    Livewire::test(ListWebhookEndpoints::class)->callTableAction('disable', $endpoint);
    expect($endpoint->fresh()->isActive())->toBeFalse();

    Livewire::test(ListWebhookEndpoints::class)->callTableAction('enable', $endpoint);
    expect($endpoint->fresh()->isActive())->toBeTrue();
});

it('shows the delivery log on the endpoint\'s edit page', function () {
    [$tenant, $admin] = webhookAdmin();
    $endpoint = WebhookEndpoint::create(['url' => 'https://institute.example.com/hook', 'secret' => 'x']);
    $delivery = WebhookDelivery::create([
        'webhook_endpoint_id' => $endpoint->id,
        'event_type' => 'payment.captured',
        'payload' => ['enrolment_id' => 1],
        'status' => 'delivered',
        'attempt_count' => 1,
        'delivered_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(WebhookEndpointResource::getUrl('edit', ['record' => $endpoint, 'subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    Livewire::test(DeliveriesRelationManager::class, [
        'ownerRecord' => $endpoint,
        'pageClass' => EditWebhookEndpoint::class,
    ])->assertCanSeeTableRecords([$delivery]);
});
