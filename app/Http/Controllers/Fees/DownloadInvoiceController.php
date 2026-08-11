<?php

namespace App\Http\Controllers\Fees;

use App\Domains\Fees\Models\FeeInvoice;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DownloadInvoiceController extends Controller
{
    /**
     * Id is read off the route rather than method-injected — same
     * domain-route splicing gotcha as StreamLessonController/
     * WatchLessonController/ShowQuizController (CLAUDE.md).
     */
    public function __invoke(Request $request): View
    {
        $invoice = FeeInvoice::with(['batch.course', 'user'])->findOrFail($request->route('invoice'));

        $viewer = $request->user();

        // Only the invoice's own student, or *this tenant's* staff, can
        // view it. Role alone is not enough — Spatie roles carry no
        // tenant_id, and SESSION_DOMAIN is a shared parent domain (see
        // EnsureRole's own docblock and EnsureRoleTest), so an
        // instructor's session cookie from tenant B stays valid on tenant
        // A's subdomain. Without the tenant_id cross-check here, that
        // instructor could open any tenant A student's invoice URL and
        // read their GSTIN, billing address, and fee amounts just by
        // guessing an id — found in the S7 B7 review pass, the same
        // finding class EnsureRole exists to close, just never applied to
        // this plain (non-Filament) controller.
        abort_unless(
            $viewer !== null && (
                $invoice->user_id === $viewer->id
                || ($viewer->hasAnyRole(['instructor', 'institute_admin']) && $viewer->tenant_id === $invoice->tenant_id)
            ),
            403,
        );

        return view('fees.invoice', [
            'invoice' => $invoice,
            'tenant' => app(CurrentTenant::class)->get(),
        ]);
    }
}
