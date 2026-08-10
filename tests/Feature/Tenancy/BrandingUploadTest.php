<?php

use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\BrandingService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');

    Route::post('/__upload_logo/{tenantId}', function (string $tenantId) {
        $tenant = Tenant::findOrFail($tenantId);

        app(BrandingService::class)->storeLogo($tenant, request()->file('logo'));

        return response()->noContent();
    });
});

it('accepts an allowed logo upload over http', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAs(User::factory()->create(['tenant_id' => $tenant->id]));

    $response = $this->post("/__upload_logo/{$tenant->id}", [
        'logo' => UploadedFile::fake()->image('logo.png'),
    ]);

    $response->assertNoContent();

    expect($tenant->refresh()->logo_path)->not->toBeNull();
});

it('rejects a disallowed logo extension over http with a field error, writing nothing', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAs(User::factory()->create(['tenant_id' => $tenant->id]));

    $response = $this->postJson("/__upload_logo/{$tenant->id}", [
        'logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'),
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('errors.logo.0', fn ($message) => str_contains($message, 'logo'));

    expect($tenant->refresh()->logo_path)->toBeNull();
    Storage::disk('public')->assertDirectoryEmpty("branding/{$tenant->id}");
});
