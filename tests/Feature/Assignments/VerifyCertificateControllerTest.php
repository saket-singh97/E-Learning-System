<?php

use App\Domains\Assignments\Models\Certificate;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function verifyCertificateFixture(): Certificate
{
    $tenant = Tenant::factory()->create(['subdomain' => 'physics', 'name' => 'Physics Institute']);
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $student = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Ada Lovelace']);

    return Certificate::create([
        'user_id' => $student->id,
        'course_id' => $course->id,
        'verification_id' => 'CERT-TESTABCDEFGH',
        'final_score' => 87.5,
        'pdf_disk_path' => 'certificates/1/1/1.pdf',
        'issued_at' => now(),
    ]);
}

it('shows a valid certificate with student, course, score, and a QR code', function () {
    verifyCertificateFixture();

    $response = $this->get('http://lms.test/certificates/verify/CERT-TESTABCDEFGH');

    $response->assertOk()
        ->assertSee('Ada Lovelace')
        ->assertSee('Physics 101')
        ->assertSee('87.5')
        ->assertSee('CERT-TESTABCDEFGH')
        ->assertSee('<svg', false);
});

it('404s on an unknown verification id', function () {
    $response = $this->get('http://lms.test/certificates/verify/CERT-DOES-NOT-EXIST');

    $response->assertNotFound();
});

it('verifies correctly even when opened on a different tenants own subdomain', function () {
    verifyCertificateFixture();

    // The lenient ResolveTenant middleware binds CurrentTenant whenever the
    // *host* resolves to a real tenant — a certificate from tenant A
    // opened while the browser happens to be on tenant B's subdomain (or
    // any subdomain other than tenant A's own) must still resolve, since
    // the verification_id — not the host — decides which certificate this
    // is (the real bug this route's withoutGlobalScope('tenant') exists to
    // avoid: without it, tenant A's certificate would silently 404 under
    // tenant B's own subdomain).
    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    app(CurrentTenant::class)->set($otherTenant);

    $response = $this->get('http://chemistry.lms.test/certificates/verify/CERT-TESTABCDEFGH');

    $response->assertOk()->assertSee('Ada Lovelace');
});

it('includes og meta tags for a clean link preview', function () {
    verifyCertificateFixture();

    $response = $this->get('http://lms.test/certificates/verify/CERT-TESTABCDEFGH');

    $response->assertSee('og:title', false)
        ->assertSee('Physics Institute');
});

it('omits og:image when the tenant has no logo, and includes it when one is set', function () {
    Storage::fake('public');
    $certificate = verifyCertificateFixture();

    $this->get('http://lms.test/certificates/verify/CERT-TESTABCDEFGH')
        ->assertDontSee('og:image', false);

    $tenant = Tenant::find($certificate->tenant_id);
    $logoPath = UploadedFile::fake()->image('logo.png')->store("branding/{$tenant->id}", 'public');
    $tenant->update(['logo_path' => $logoPath]);

    $this->get('http://lms.test/certificates/verify/CERT-TESTABCDEFGH')
        ->assertSee('og:image', false)
        ->assertSee(Storage::disk('public')->url($logoPath), false);
});

it('builds a LinkedIn add-to-profile link with the documented certification parameters', function () {
    $certificate = verifyCertificateFixture();

    $response = $this->get('http://lms.test/certificates/verify/CERT-TESTABCDEFGH');

    $response->assertSee('https://www.linkedin.com/profile/add', false)
        ->assertSee('startTask=CERTIFICATION_NAME', false)
        ->assertSee('name=Physics+101', false)
        ->assertSee('organizationName=Physics+Institute', false)
        ->assertSee('certId=CERT-TESTABCDEFGH', false)
        ->assertSee('certUrl=' . urlencode('http://lms.test/certificates/verify/CERT-TESTABCDEFGH'), false);
});
