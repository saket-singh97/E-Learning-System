<?php

namespace App\Http\Controllers\Comms;

use App\Domains\Comms\Jobs\ExportCompletionRegister;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Same shape as DownloadEnrolmentExportController (B18) — copied exactly,
 * not a new pattern (design decision 12).
 */
class DownloadCompletionExportController extends Controller
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

        $path = ExportCompletionRegister::pathFor(app(CurrentTenant::class)->id());

        abort_unless(Storage::disk('local')->exists($path), 404, 'Completion register has not been generated yet.');

        return Storage::disk('local')->download($path, 'completion-register.csv');
    }
}
