<?php

namespace App\Http\Controllers\Assessments;

use App\Domains\Assessments\Models\HallTicket;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * S23 B17 (U14). `signed`-middleware-only, same shape
 * DownloadCalendarFeedController already established: possessing the exact
 * signed URL is the access token — a registrant here has no `User` row to
 * authenticate as (design §4 decision 2's own "no-User-row" posture), so
 * there's no session-based gate to fall back to. `URL::signedRoute()`, not
 * `temporarySignedRoute()` — handed once on the registration confirmation
 * page with no delivery channel to refresh it later, so it must keep
 * working whenever the registrant returns to print it before the exam
 * (same no-expiry reasoning as the calendar feed).
 *
 * {hallTicket} read off the route, not method-injected — CLAUDE.md's
 * domain-route splicing gotcha.
 */
class DownloadHallTicketController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $hallTicket = HallTicket::findOrFail($request->route('hallTicket'));

        abort_unless(Storage::disk('local')->exists($hallTicket->pdf_path), 404);

        return Storage::disk('local')->download($hallTicket->pdf_path, "hall-ticket-{$hallTicket->roll_number}.pdf");
    }
}
