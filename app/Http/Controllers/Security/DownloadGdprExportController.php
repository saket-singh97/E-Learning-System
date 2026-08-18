<?php

namespace App\Http\Controllers\Security;

use App\Domains\Security\Models\GdprExportRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * `signed`-middleware-only, no separate role check — same shape as
 * `StreamLessonController`/`DownloadLessonPdfController` (routes/content.php):
 * possessing the exact signed URL is the access token here, matching a
 * link a staff member hands to (or downloads for) the data subject.
 *
 * The export id is read off the route rather than method-injected —
 * `CLAUDE.md`'s own documented gotcha: this route lives under
 * `Route::domain('{subdomain}.lms.test')`, and a scalar route parameter
 * gets spliced into the wrong argument once a class-typehinted parameter
 * (`Request`) sits alongside it.
 */
class DownloadGdprExportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $exportRequest = GdprExportRequest::findOrFail($request->route('gdprExportRequest'));

        abort_unless($exportRequest->status === GdprExportRequest::STATUS_READY, 404);
        abort_unless($exportRequest->export_disk_path !== null, 404);
        abort_if($exportRequest->expires_at !== null && $exportRequest->expires_at->isPast(), 410);

        return Storage::disk('local')->download(
            $exportRequest->export_disk_path,
            "gdpr-export-{$exportRequest->user_id}.zip",
        );
    }
}
