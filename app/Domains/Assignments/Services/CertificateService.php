<?php

namespace App\Domains\Assignments\Services;

use App\Domains\Assignments\Models\Certificate;
use App\Domains\Billing\Services\StorageQuota;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\BrandingService;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Called from CheckCourseCompletionAndIssueCertificates (B11), an unscoped
 * daily sweep that runs with no CurrentTenant bound (design doc §3.3) — so
 * Certificate::create() below runs inside withoutEvents() with tenant_id
 * set explicitly, the same shape RecordVideoCoverageAttendance (S5 B7)
 * already established for creating a BelongsToTenant row from that exact
 * position (HANDOVER's own "Avoid" section documents why: the trait's
 * creating() hook calls CurrentTenant::id() unconditionally and throws
 * MissingTenantContextException when nothing is bound).
 */
class CertificateService
{
    public function __construct(
        private readonly StorageQuota $storageQuota,
    ) {}

    public function issue(User $student, Course $course, float $finalScore): Certificate
    {
        $verificationId = 'CERT-'.strtoupper(Str::random(12));
        $pdfDiskPath = "certificates/{$student->tenant_id}/{$course->id}/{$student->id}.pdf";
        $tenant = Tenant::findOrFail($student->tenant_id);
        $branding = app(BrandingService::class);

        Pdf::loadView('assignments.certificate-pdf', [
            'tenant' => $tenant,
            'student' => $student,
            'course' => $course,
            'finalScore' => $finalScore,
            'verificationId' => $verificationId,
            'issuedAt' => now(),
            // Absolute local disk paths (B12) — dompdf reads images
            // straight off disk, no public URL/remote-fetch detour needed
            // for an asset already sitting on the same filesystem. Null
            // when a tenant hasn't uploaded one yet; the template omits
            // that <img> entirely rather than rendering a broken one.
            'logoPath' => $tenant->logo_path ? Storage::disk('public')->path($tenant->logo_path) : null,
            'signaturePath' => $branding->certificateSignaturePath($tenant),
            'backgroundPath' => $branding->certificateBackgroundPath($tenant),
        ])->save($pdfDiskPath, 'local');

        // S11 B4 — tracked, deliberately not gated. This runs from an
        // unscoped daily sweep with no interactive user watching it retry
        // (design doc §3.3 header); blocking a student's already-earned
        // certificate because the *institute's* storage happens to be full
        // is a worse failure mode than letting one certificate go through,
        // unlike the interactive uploads (assignment submissions, lesson
        // PDFs, branding) B4's other call sites correctly do block.
        $this->storageQuota->adjust($tenant, Storage::disk('local')->size($pdfDiskPath));

        return Certificate::withoutEvents(fn () => Certificate::create([
            'tenant_id' => $student->tenant_id,
            'user_id' => $student->id,
            'course_id' => $course->id,
            'verification_id' => $verificationId,
            'final_score' => $finalScore,
            'pdf_disk_path' => $pdfDiskPath,
            'issued_at' => now(),
        ]));
    }
}
