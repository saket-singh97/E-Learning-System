<?php

use App\Domains\Security\Models\GdprExportRequest;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);
    $this->subject = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->staff = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

function gdprSignedDownloadUrl(GdprExportRequest $request, ?\DateTimeInterface $expiry = null): string
{
    return URL::temporarySignedRoute('gdpr-export.download', $expiry ?? now()->addDays(7), [
        'subdomain' => 'physics',
        'gdprExportRequest' => $request->id,
    ]);
}

it('downloads a ready, unexpired export via its signed url', function () {
    $request = GdprExportRequest::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->subject->id,
        'requested_by_id' => $this->staff->id,
        'status' => GdprExportRequest::STATUS_READY,
        'export_disk_path' => 'gdpr-exports/download-test.zip',
        'expires_at' => now()->addDays(7),
    ]);
    Storage::disk('local')->put($request->export_disk_path, 'zip-bytes');

    $this->get(gdprSignedDownloadUrl($request))->assertOk();
});

it('refuses a tampered or unsigned url', function () {
    $request = GdprExportRequest::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->subject->id,
        'requested_by_id' => $this->staff->id,
        'status' => GdprExportRequest::STATUS_READY,
        'export_disk_path' => 'gdpr-exports/download-test.zip',
        'expires_at' => now()->addDays(7),
    ]);
    Storage::disk('local')->put($request->export_disk_path, 'zip-bytes');

    $this->get("http://physics.lms.test/gdpr-exports/{$request->id}/download")
        ->assertForbidden();
});

it('404s a request that is not ready yet', function () {
    $request = GdprExportRequest::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->subject->id,
        'requested_by_id' => $this->staff->id,
        'status' => GdprExportRequest::STATUS_PROCESSING,
    ]);

    $this->get(gdprSignedDownloadUrl($request))->assertNotFound();
});

it('returns 410 once the export has expired', function () {
    $request = GdprExportRequest::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->subject->id,
        'requested_by_id' => $this->staff->id,
        'status' => GdprExportRequest::STATUS_READY,
        'export_disk_path' => 'gdpr-exports/download-test.zip',
        'expires_at' => now()->subDay(),
    ]);
    Storage::disk('local')->put($request->export_disk_path, 'zip-bytes');

    $this->get(gdprSignedDownloadUrl($request, now()->addDays(30)))
        ->assertStatus(410);
});
