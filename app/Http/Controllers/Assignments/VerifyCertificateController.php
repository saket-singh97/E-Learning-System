<?php

namespace App\Http\Controllers\Assignments;

use App\Domains\Assignments\Models\Certificate;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Public, no auth — same posture S9 B5's invite-redemption route took for
 * "tenant comes from the identifier, not the host." Deliberately
 * `withoutGlobalScope('tenant')`, not left to rely on CurrentTenant
 * happening to be unbound: this route carries no domain constraint, but
 * the lenient ResolveTenant middleware still binds CurrentTenant whenever
 * the request's *host* happens to resolve to a real tenant (CLAUDE.md) —
 * so a certificate scanned/opened while sitting on a *different*
 * institute's subdomain would otherwise silently 404 as if it didn't
 * exist, even though the verification_id genuinely belongs to some other
 * tenant. The id itself is the only thing that should decide which
 * certificate this is.
 */
class VerifyCertificateController extends Controller
{
    public function __invoke(Request $request): View
    {
        // withoutGlobalScope('tenant') on the Certificate query alone isn't
        // enough — Course is BelongsToTenant too (User isn't, confirmed by
        // reading the model), so eager-loading it without its own
        // withoutGlobalScope() silently re-applies the tenant filter one
        // level down: whatever tenant *this request's own host* happens to
        // resolve to, not the certificate's actual tenant. A cross-tenant
        // verification would 500 on a null ->course instead of rendering
        // (found running this exact scenario, not spotted by inspection).
        $certificate = Certificate::withoutGlobalScope('tenant')
            ->with([
                'user',
                'course' => fn ($query) => $query->withoutGlobalScope('tenant'),
            ])
            ->where('verification_id', $request->route('verificationId'))
            ->first();

        // A flat 404 either way — no behavioural difference between "no
        // such id" and "id from a suspended tenant," so neither case leaks
        // which (plan doc's own instruction).
        abort_if($certificate === null, 404);

        $tenant = Tenant::find($certificate->tenant_id);

        // Encodes this same verify URL — a printed certificate's QR code
        // scans straight back to this page, not a separate deep link that
        // could drift from it.
        $qrCode = (new SvgWriter())->write(new QrCode(data: $request->url()));

        return view('assignments.certificate-verify', [
            'certificate' => $certificate,
            'tenant' => $tenant,
            'qrCodeSvg' => $qrCode->getString(),
            'ogImageUrl' => $tenant->logo_path ? Storage::disk('public')->url($tenant->logo_path) : null,
            // LinkedIn's own documented "Add to Profile" static URL (checked
            // against https://www.linkedin.com/help/linkedin/answer/a528030
            // and its own microsite before building this — the plan note's
            // own warning that these params have changed before). No OAuth,
            // no vendor account, same "real, zero-setup" posture
            // ExternalLinkProvider (S5) already established for live
            // classes — organizationName, not organizationId, since an
            // institute tenant has no registered LinkedIn Company Page to
            // reference.
            'linkedInShareUrl' => 'https://www.linkedin.com/profile/add?'.http_build_query([
                'startTask' => 'CERTIFICATION_NAME',
                'name' => $certificate->course->title,
                'organizationName' => $tenant->legal_name ?? $tenant->name,
                'issueYear' => $certificate->issued_at->year,
                'issueMonth' => $certificate->issued_at->month,
                'certId' => $certificate->verification_id,
                'certUrl' => $request->url(),
            ]),
        ]);
    }
}
