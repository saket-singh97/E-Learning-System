<?php

namespace App\Http\Controllers\Batches;

use App\Domains\Batches\Models\Enrolment;
use App\Domains\Batches\Policies\EnrolmentApprovalPolicy;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * B21 review-pass fix: the original role-only check (institute_admin OR
 * instructor) let any instructor in the tenant download any other
 * instructor's own batch's ID cards — the exact per-instructor boundary
 * B2 closed everywhere else in this slice. Reuses
 * EnrolmentApprovalPolicy::canDecide() (already the canonical "may this
 * actor act on this batch" check) instead of a bespoke role list — its
 * institute_admin branch also closes the sibling cross-tenant gap (a
 * same-role actor from a different tenant, reachable because Filament
 * roles carry no tenant_id of their own). {enrolment} resolved via
 * $request->route(), not a type-hinted route-model-bound parameter
 * (CLAUDE.md's domain-route splicing gotcha).
 */
class DownloadIdCardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $viewer = $request->user();

        abort_unless($viewer !== null && $viewer->hasAnyRole(EnrolmentApprovalPolicy::DECIDER_ROLES), 403);

        $enrolment = Enrolment::find($request->route('enrolment'));

        abort_unless($enrolment !== null, 404);

        abort_unless(app(EnrolmentApprovalPolicy::class)->canDecide($viewer, $enrolment->batch), 403);

        $idCard = $enrolment->idCard;

        abort_unless($idCard !== null && Storage::disk('local')->exists($idCard->disk_path), 404, 'No ID card has been issued for this enrolment yet.');

        return Storage::disk('local')->download($idCard->disk_path, "id-card-{$idCard->card_number}.pdf");
    }
}
