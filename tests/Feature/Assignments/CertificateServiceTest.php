<?php

use App\Domains\Assignments\Models\Certificate;
use App\Domains\Assignments\Services\CertificateService;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\BrandingService;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

it('issues a certificate with a real pdf file, even with no CurrentTenant bound', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $student = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Ada Lovelace']);
    app(CurrentTenant::class)->clear();

    $certificate = app(CertificateService::class)->issue($student, $course, 82.5);

    expect($certificate->tenant_id)->toBe($tenant->id)
        ->and($certificate->user_id)->toBe($student->id)
        ->and($certificate->course_id)->toBe($course->id)
        ->and((float) $certificate->final_score)->toBe(82.5)
        ->and($certificate->verification_id)->toStartWith('CERT-')
        ->and($certificate->issued_at)->not->toBeNull();

    expect(Storage::disk('local')->exists($certificate->pdf_disk_path))->toBeTrue();
    $bytes = Storage::disk('local')->get($certificate->pdf_disk_path);
    expect(substr($bytes, 0, 4))->toBe('%PDF');
});

it('generates a unique verification id per certificate', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $studentA = User::factory()->create(['tenant_id' => $tenant->id]);
    $studentB = User::factory()->create(['tenant_id' => $tenant->id]);

    $service = app(CertificateService::class);
    $certA = $service->issue($studentA, $course, 90);
    $certB = $service->issue($studentB, $course, 91);

    expect($certA->verification_id)->not->toBe($certB->verification_id);
});

it('refuses a second certificate for the same student and course, via the DB unique index', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $student = User::factory()->create(['tenant_id' => $tenant->id]);

    app(CertificateService::class)->issue($student, $course, 90);

    // Wrapped in its own DB::transaction() (a Postgres SAVEPOINT, nested
    // inside RefreshDatabase's own per-test transaction) — without it, the
    // expected QueryException leaves the whole outer transaction aborted
    // (SQLSTATE 25P02), and the Certificate::count() assertion right after
    // would itself fail with "current transaction is aborted," a test
    // artifact that has nothing to do with the real behaviour being
    // asserted (found running this exact test).
    expect(fn () => DB::transaction(fn () => app(CertificateService::class)->issue($student, $course, 95)))
        ->toThrow(\Illuminate\Database\QueryException::class);

    expect(Certificate::count())->toBe(1);
});

it('still generates a real pdf once a tenant has logo/signature/background branding set (B12)', function () {
    Storage::fake('public');

    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $logoPath = UploadedFile::fake()->image('logo.png')->store("branding/{$tenant->id}", 'public');
    $tenant->update(['logo_path' => $logoPath]);

    $tempSignature = UploadedFile::fake()->image('sig.png')->store('certificate-branding/tmp', 'local');
    app(BrandingService::class)->storeCertificateSignature($tenant, $tempSignature);
    $tenant->refresh();

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $student = User::factory()->create(['tenant_id' => $tenant->id]);

    $certificate = app(CertificateService::class)->issue($student, $course, 88);

    $bytes = Storage::disk('local')->get($certificate->pdf_disk_path);
    expect(substr($bytes, 0, 4))->toBe('%PDF');
});
