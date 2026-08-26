<?php

namespace App\Http\Controllers\Analytics;

use App\Domains\Analytics\Models\MonthlyReportCard;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * `signed`-middleware-only, same shape DownloadGdprExportController already
 * uses — possessing the exact signed URL (handed to a parent over WhatsApp)
 * is the access token here, no separate auth/role check.
 *
 * {monthlyReportCard} read off the route rather than method-injected —
 * CLAUDE.md's domain-route splicing gotcha on Route::domain() routes.
 */
class DownloadReportCardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $card = MonthlyReportCard::findOrFail($request->route('monthlyReportCard'));

        abort_unless(in_array($card->status, ['generated', 'sent'], true), 404);
        abort_unless($card->pdf_disk_path !== null, 404);

        return Storage::disk('local')->download(
            $card->pdf_disk_path,
            "report-card-{$card->period_month->format('Y-m')}.pdf",
        );
    }
}
