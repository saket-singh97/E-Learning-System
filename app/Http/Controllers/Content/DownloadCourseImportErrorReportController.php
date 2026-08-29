<?php

namespace App\Http\Controllers\Content;

use App\Domains\Content\Models\CourseImportBatch;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Same gated shape as DownloadStudentImportErrorReportController (S15) —
 * role check + explicit tenant_id check inside the controller, not left to
 * BelongsToTenant's global scope alone (CLAUDE.md's EnsureRole
 * double-check posture). {importBatch} resolved via $request->route(), not
 * a type-hinted route-model-bound parameter (CLAUDE.md's domain-route
 * splicing gotcha).
 */
class DownloadCourseImportErrorReportController extends Controller
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

        $importBatch = CourseImportBatch::find($request->route('importBatch'));

        abort_unless($importBatch !== null, 404);

        abort_unless(
            $importBatch->error_report_path !== null
                && Storage::disk('local')->exists($importBatch->error_report_path),
            404,
            'The error report has not been generated yet.',
        );

        return Storage::disk('local')->download(
            $importBatch->error_report_path,
            "course-import-{$importBatch->id}-errors.csv",
        );
    }
}
