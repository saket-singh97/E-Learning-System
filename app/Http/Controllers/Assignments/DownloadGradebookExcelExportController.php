<?php

namespace App\Http\Controllers\Assignments;

use App\Domains\Assignments\Jobs\ExportGradebookExcel;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Same shape as DownloadGradebookExportController (B9) — copied, not a new
 * pattern.
 */
class DownloadGradebookExcelExportController extends Controller
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

        $path = ExportGradebookExcel::pathFor(app(CurrentTenant::class)->id());

        abort_unless(Storage::disk('local')->exists($path), 404, 'Gradebook export has not been generated yet.');

        return Storage::disk('local')->download($path, 'gradebook.xlsx');
    }
}
