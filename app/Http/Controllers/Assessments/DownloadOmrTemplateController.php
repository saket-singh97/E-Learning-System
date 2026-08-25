<?php

namespace App\Http\Controllers\Assessments;

use App\Domains\Assessments\Models\OmrTemplate;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * B17 review pass — `OmrTemplateGenerator` saved the printable PDF to
 * disk with nothing to actually retrieve it: no controller/route existed
 * anywhere, unlike the analogous `DownloadIdCardController` (S15). The
 * "Generate OMR template" action created a file the admin could never
 * download or print, breaking I11's own first step.
 */
class DownloadOmrTemplateController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $template = OmrTemplate::findOrFail($request->route('template'));

        abort_unless(
            $request->user() !== null
                && $request->user()->hasAnyRole(['instructor', 'institute_admin'])
                && $request->user()->tenant_id === app(CurrentTenant::class)->id(),
            403,
        );

        $diskPath = "omr-templates/{$template->tenant_id}/{$template->id}.pdf";

        abort_unless(Storage::disk('local')->exists($diskPath), 404);

        return Storage::disk('local')->download($diskPath, 'omr-template.pdf');
    }
}
