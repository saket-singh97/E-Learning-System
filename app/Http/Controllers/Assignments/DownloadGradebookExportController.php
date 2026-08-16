<?php

namespace App\Http\Controllers\Assignments;

use App\Domains\Assignments\Jobs\ExportGradebookCsv;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Same shape as DownloadEngagementExportController (S9 B11) — copied, not
 * a new pattern. Gated to both staff roles (not institute_admin only, the
 * way the engagement/attendance exports are) since the gradebook itself
 * (B8) is the same table on both /teach and /admin — an instructor can
 * download the export they can already view.
 */
class DownloadGradebookExportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $viewer = $request->user();

        abort_unless(
            $viewer !== null
                && $viewer->hasAnyRole(['instructor', 'institute_admin'])
                && $viewer->tenant_id === app(CurrentTenant::class)->id(),
            403,
        );

        $path = ExportGradebookCsv::pathFor(app(CurrentTenant::class)->id());

        abort_unless(Storage::disk('local')->exists($path), 404, 'Gradebook export has not been generated yet.');

        return Storage::disk('local')->download($path, 'gradebook.csv');
    }
}
