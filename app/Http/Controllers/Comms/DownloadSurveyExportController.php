<?php

namespace App\Http\Controllers\Comms;

use App\Domains\Comms\Jobs\ExportSurveyResponsesCsv;
use App\Domains\Comms\Models\Survey;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Same shape as DownloadCompletionExportController et al. (S9 B11) — one
 * new wrinkle: the export is per-survey, so `survey` is pulled via
 * `$request->route()` (CLAUDE.md's domain-route splicing gotcha), not a
 * type-hinted `Survey $survey` parameter, and the survey itself must belong
 * to the current tenant before anything else runs.
 */
class DownloadSurveyExportController extends Controller
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

        $survey = Survey::find($request->route('survey'));

        abort_unless($survey !== null, 404);

        $path = ExportSurveyResponsesCsv::pathFor($survey->id);

        abort_unless(Storage::disk('local')->exists($path), 404, 'Survey export has not been generated yet.');

        return Storage::disk('local')->download($path, "survey-{$survey->id}-responses.csv");
    }
}
