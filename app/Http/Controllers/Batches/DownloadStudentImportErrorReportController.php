<?php

namespace App\Http\Controllers\Batches;

use App\Domains\Batches\Models\StudentImportBatch;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Same gated shape as DownloadRevenueExportController — role check
 * inside the controller (CLAUDE.md's EnsureRole double-check posture),
 * plus the tenant-scoped find() below, which already 404s a cross-tenant
 * id on its own (StudentImportBatch's BelongsToTenant global scope).
 * {importBatch} resolved via $request->route(), not a type-hinted
 * route-model-bound parameter (CLAUDE.md's domain-route splicing gotcha).
 */
class DownloadStudentImportErrorReportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $viewer = $request->user();

        abort_unless($viewer !== null && $viewer->hasAnyRole(['institute_admin']), 403);

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
