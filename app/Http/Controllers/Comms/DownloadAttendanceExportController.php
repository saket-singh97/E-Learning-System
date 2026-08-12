<?php

namespace App\Http\Controllers\Comms;

use App\Domains\Comms\Jobs\ExportAttendanceRegister;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class DownloadAttendanceExportController extends Controller
{
    /**
     * Same shape as DownloadInvoiceController (S7 B7): no `auth`
     * middleware at the route level, the `abort_unless` below is the
     * entire gate (auth + tenant cross-check in one). institute_admin
     * only — same role /admin's own EnsureRole panel gate already
     * requires, checked again here since this is a plain route outside
     * Filament's own middleware stack.
     */
    public function __invoke(Request $request): Response
    {
        $viewer = $request->user();

        abort_unless(
            $viewer !== null
                && $viewer->hasAnyRole(['institute_admin'])
                && $viewer->tenant_id === app(CurrentTenant::class)->id(),
            403,
        );

        $path = ExportAttendanceRegister::pathFor(app(CurrentTenant::class)->id());

        abort_unless(Storage::disk('local')->exists($path), 404, 'Attendance register has not been generated yet.');

        return Storage::disk('local')->download($path, 'attendance-register.csv');
    }
}
