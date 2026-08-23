<?php

namespace App\Http\Controllers\Batches;

use App\Domains\Batches\Models\Enrolment;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Same gated shape as DownloadStudentImportErrorReportController (B10) —
 * role + tenant-scoped `find()` inside the controller, no extra route
 * middleware (matches DownloadGradebookPdfExportController's own bare
 * route registration). {enrolment} resolved via $request->route(), not
 * a type-hinted route-model-bound parameter (CLAUDE.md's domain-route
 * splicing gotcha).
 */
class DownloadIdCardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $viewer = $request->user();

        abort_unless($viewer !== null && $viewer->hasAnyRole(['institute_admin', 'instructor']), 403);

        $enrolment = Enrolment::find($request->route('enrolment'));

        abort_unless($enrolment !== null, 404);

        $idCard = $enrolment->idCard;

        abort_unless($idCard !== null && Storage::disk('local')->exists($idCard->disk_path), 404, 'No ID card has been issued for this enrolment yet.');

        return Storage::disk('local')->download($idCard->disk_path, "id-card-{$idCard->card_number}.pdf");
    }
}
