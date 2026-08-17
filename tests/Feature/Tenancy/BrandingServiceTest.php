<?php

use App\Domains\Billing\Services\StorageQuota;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\BrandingService;
use App\Exceptions\InvalidFileTypeException;
use App\Exceptions\StorageLimitExceededException;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
});

it('exposes css custom properties from the current tenant colors', function () {
    $tenant = Tenant::factory()->create([
        'primary_color' => '#112233',
        'accent_color' => '#445566',
    ]);
    app(CurrentTenant::class)->set($tenant);

    $service = app(BrandingService::class);

    expect($service->primaryColor())->toBe('#112233');
    expect($service->accentColor())->toBe('#445566');
    expect($service->cssVariables())->toBe('--color-primary: #112233; --color-accent: #445566;');
});

it('falls back to default colors when no tenant is bound', function () {
    $service = app(BrandingService::class);

    expect($service->primaryColor())->toBe(BrandingService::DEFAULT_PRIMARY_COLOR);
    expect($service->accentColor())->toBe(BrandingService::DEFAULT_ACCENT_COLOR);
});

it('returns null logo and favicon urls when the tenant has none stored', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $service = app(BrandingService::class);

    expect($service->logoUrl())->toBeNull();
    expect($service->faviconUrl())->toBeNull();
});

it('stores an allowed logo upload and updates the tenant logo_path', function () {
    $tenant = Tenant::factory()->create();
    $file = UploadedFile::fake()->image('logo.png');

    app(BrandingService::class)->storeLogo($tenant, $file);

    $tenant->refresh();
    expect($tenant->logo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($tenant->logo_path);
});

it('rejects a disallowed logo extension and writes nothing', function () {
    $tenant = Tenant::factory()->create();
    $file = UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml');

    $exception = null;

    try {
        app(BrandingService::class)->storeLogo($tenant, $file);
    } catch (InvalidFileTypeException $e) {
        $exception = $e;
    }

    expect($exception)->not->toBeNull();
    expect($exception->field)->toBe('logo');

    $tenant->refresh();
    expect($tenant->logo_path)->toBeNull();
    Storage::disk('public')->assertDirectoryEmpty("branding/{$tenant->id}");
});

it('stores a certificate signature from a FileUpload temp path and cleans up the temp file', function () {
    Storage::fake('local');
    $tenant = Tenant::factory()->create();
    $tempPath = UploadedFile::fake()->image('sig.png')->store('certificate-branding/tmp', 'local');

    app(BrandingService::class)->storeCertificateSignature($tenant, $tempPath);

    $tenant->refresh();
    expect($tenant->certificate_signature_path)->toBe("branding/{$tenant->id}/certificate-signature.png");
    Storage::disk('public')->assertExists($tenant->certificate_signature_path);
    Storage::disk('local')->assertMissing($tempPath);
});

it('stores a certificate background the same way', function () {
    Storage::fake('local');
    $tenant = Tenant::factory()->create();
    $tempPath = UploadedFile::fake()->image('bg.jpg')->store('certificate-branding/tmp', 'local');

    app(BrandingService::class)->storeCertificateBackground($tenant, $tempPath);

    $tenant->refresh();
    expect($tenant->certificate_background_path)->toBe("branding/{$tenant->id}/certificate-background.jpg");
    Storage::disk('public')->assertExists($tenant->certificate_background_path);
});

it('returns absolute local disk paths for certificate branding, or null when unset', function () {
    $tenant = Tenant::factory()->create();
    $service = app(BrandingService::class);

    expect($service->certificateSignaturePath($tenant))->toBeNull()
        ->and($service->certificateBackgroundPath($tenant))->toBeNull();

    Storage::fake('local');
    $tempPath = UploadedFile::fake()->image('sig.png')->store('certificate-branding/tmp', 'local');
    $service->storeCertificateSignature($tenant, $tempPath);
    $tenant->refresh();

    expect($service->certificateSignaturePath($tenant))
        ->toBe(Storage::disk('public')->path($tenant->certificate_signature_path));
});

it('tracks a logo upload against the tenant storage counter (S11 B4)', function () {
    $tenant = Tenant::factory()->create();
    $file = UploadedFile::fake()->create('logo.png', 200, 'image/png');

    app(BrandingService::class)->storeLogo($tenant, $file);

    expect(app(StorageQuota::class)->usedNow($tenant))->toBe(200 * 1024);
});

it('does not double-count a same-extension logo re-upload, only the size delta', function () {
    $tenant = Tenant::factory()->create();

    app(BrandingService::class)->storeLogo($tenant, UploadedFile::fake()->create('logo.png', 200, 'image/png'));
    $tenant->refresh();

    // UploadedFile::fake()->create() writes no real bytes into
    // Storage::fake() (LessonContentTypesTest documents the identical
    // gotcha for the 'local' disk) — the *new* file's size is read from
    // the UploadedFile's own metadata (correct either way), but the
    // *previous* file's size is read back via Storage::size(), which
    // needs real bytes on the fake disk to report anything but 0.
    Storage::disk('public')->put($tenant->logo_path, str_repeat('x', 200 * 1024));

    app(BrandingService::class)->storeLogo($tenant, UploadedFile::fake()->create('logo.png', 350, 'image/png'));

    // Same computed path both times (branding/{tenant}/logo.png) — usage
    // should reflect the final file's size, not 200 + 350.
    expect(app(StorageQuota::class)->usedNow($tenant))->toBe(350 * 1024);
});

it('blocks a branding upload once the tenant storage limit is reached', function () {
    $tenant = Tenant::factory()->create();
    app(StorageQuota::class)->adjust($tenant, (5 * 1024 * 1024 * 1024) - (50 * 1024));

    $exception = null;

    try {
        app(BrandingService::class)->storeLogo($tenant, UploadedFile::fake()->create('logo.png', 200, 'image/png'));
    } catch (StorageLimitExceededException $e) {
        $exception = $e;
    }

    expect($exception)->not->toBeNull();
    $tenant->refresh();
    expect($tenant->logo_path)->toBeNull();
});
