<?php

use App\Domains\Tenancy\Models\Tenant;
use App\Filament\Admin\Pages\CertificateSettings;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin->assignRole('institute_admin');

    $this->actingAs($this->admin)
        ->get(CertificateSettings::getUrl(['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();
});

it('saves a signature and background upload to the tenant', function () {
    Livewire::test(CertificateSettings::class)
        ->fillForm([
            'signature' => UploadedFile::fake()->image('signature.png'),
            'background' => UploadedFile::fake()->image('background.jpg'),
        ])
        ->callAction('save');

    $this->tenant->refresh();

    expect($this->tenant->certificate_signature_path)->toBe("branding/{$this->tenant->id}/certificate-signature.png")
        ->and($this->tenant->certificate_background_path)->toBe("branding/{$this->tenant->id}/certificate-background.jpg");

    Storage::disk('public')->assertExists($this->tenant->certificate_signature_path);
    Storage::disk('public')->assertExists($this->tenant->certificate_background_path);
});

it('shows the current signature status without touching the stored path on an unrelated save', function () {
    $this->tenant->update([
        'certificate_signature_path' => 'branding/existing/certificate-signature.png',
    ]);

    Livewire::test(CertificateSettings::class)
        ->assertSee('Uploaded')
        ->callAction('save');

    expect($this->tenant->fresh()->certificate_signature_path)->toBe('branding/existing/certificate-signature.png');
});

it('shows "None uploaded yet" when no signature exists', function () {
    Livewire::test(CertificateSettings::class)
        ->assertSee('None uploaded yet');
});

it('lets an admin replace an existing signature with a new upload', function () {
    $this->tenant->update([
        'certificate_signature_path' => 'branding/existing/certificate-signature.png',
    ]);

    Livewire::test(CertificateSettings::class)
        ->fillForm([
            'signature' => UploadedFile::fake()->image('new-signature.png'),
        ])
        ->callAction('save');

    $this->tenant->refresh();

    expect($this->tenant->certificate_signature_path)->toBe("branding/{$this->tenant->id}/certificate-signature.png");
    Storage::disk('public')->assertExists($this->tenant->certificate_signature_path);
});
