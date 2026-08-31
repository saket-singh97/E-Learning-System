<?php

namespace App\Http\Controllers\Assessments;

use App\Domains\Assessments\Models\RankCard;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * S23 B16 (U15 pt.2). Same owner-or-tenant-staff gate
 * DownloadInvoiceController already established for a student-owned PDF on
 * a plain (non-Filament) route — role alone isn't enough since Spatie
 * roles carry no tenant_id (a same-role actor from another tenant would
 * otherwise read any student's rank card by guessing an id).
 * {rankCard} read off the route, not method-injected — CLAUDE.md's
 * domain-route splicing gotcha.
 */
class DownloadRankCardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $rankCard = RankCard::findOrFail($request->route('rankCard'));

        $viewer = $request->user();

        abort_unless(
            $viewer !== null && (
                $rankCard->user_id === $viewer->id
                || ($viewer->hasAnyRole(['instructor', 'institute_admin']) && $viewer->tenant_id === $rankCard->tenant_id)
            ),
            403,
        );

        abort_unless(Storage::disk('local')->exists($rankCard->pdf_path), 404);

        return Storage::disk('local')->download($rankCard->pdf_path, "rank-card-{$rankCard->test_series_id}.pdf");
    }
}
