<?php

namespace App\Http\Controllers\Batches;

use App\Domains\Batches\Models\StudentImportBatch;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Same gated shape as DownloadRevenueExportController/DownloadEnrolmentExportController
 * — role check + explicit `$viewer->tenant_id === CurrentTenant::id()`
 * check inside the controller (CLAUDE.md's EnsureRole double-check
 * posture). B21 review-pass fix: the explicit tenant check was missing
 * here — relying on StudentImportBatch's BelongsToTenant global scope
 * alone leaves a same-role institute_admin logged in on a *different*
 * tenant's subdomain able to resolve and download another institute's
 * CSV error report (which contains student names/emails/phones), since
 * Spatie roles carry no tenant_id of their own and SESSION_DOMAIN is a
 * shared parent domain. {importBatch} resolved via $request->route(),
 * not a type-hinted route-model-bound parameter (CLAUDE.md's domain-route
 * splicing gotcha).
 */
class DownloadStudentImportErrorReportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $viewer = $request->user();

        abort_unless(
            $viewer !== null
                && $viewer->hasAnyRole(['institute_admin'])
                && $viewer->tenant_id === app(CurrentTenant::class)->id(),
            403,
        );

        $importBatch = StudentImportBatch::find($request->route('importBatch'));

        abort_unless($importBatch !== null, 404);

        abort_unless(
            $importBatch->error_report_disk_path !== null
                && Storage::disk('local')->exists($importBatch->error_report_disk_path),
            404,
            'The error report has not been generated yet.',
        );

        return Storage::disk('local')->download(
            $importBatch->error_report_disk_path,
            "student-import-{$importBatch->id}-errors.csv",
        );
    }
}
