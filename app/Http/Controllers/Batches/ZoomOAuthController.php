<?php

namespace App\Http\Controllers\Batches;

use App\Domains\Batches\Models\InstituteZoomConnection;
use App\Domains\Tenancy\Models\Tenant;
use App\Filament\Admin\Pages\ZoomConnection;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Per-institute Zoom Meetings OAuth (G7 pt.1, design doc decision 2) — an
 * institute_admin connects their own tenant's Zoom account once;
 * ZoomMeetingsProvider (B7) authenticates every meeting it creates for that
 * tenant's batch sessions against the resulting stored connection.
 *
 * No Socialite driver for Zoom exists (only laravel/socialite's own
 * built-in providers) — same reasoning B7's own ZoomMeetingsProvider talks
 * to Zoom's REST API directly rather than pulling in a new package, this
 * controller talks to Zoom's OAuth endpoints directly too.
 *
 * redirect() runs on the tenant's own subdomain (route-gated
 * auth+institute_admin), same as GoogleAuthController's own redirect().
 * Zoom's own redirect_uri must be one fixed, pre-registered URL — it
 * cannot vary per subdomain — so callback() is a host-agnostic route
 * (routes/web.php) with no domain constraint, same "tenant id carried in
 * the session, not the host" shape GoogleAuthController's own docblock
 * already established.
 */
class ZoomOAuthController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        $tenant = app(CurrentTenant::class)->get();

        session([
            'zoom_oauth.tenant_id' => $tenant->id,
            'zoom_oauth.user_id' => $request->user()->id,
        ]);

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.zoom.oauth_client_id'),
            'redirect_uri' => route('zoom.oauth.callback'),
        ]);

        return redirect()->away("https://zoom.us/oauth/authorize?{$query}");
    }

    public function callback(Request $request): RedirectResponse
    {
        $tenantId = $request->session()->pull('zoom_oauth.tenant_id');
        $userId = $request->session()->pull('zoom_oauth.user_id');

        abort_unless($tenantId && $userId, 419);

        $tenant = Tenant::findOrFail($tenantId);
        $code = $request->query('code');

        abort_unless(filled($code), 422);

        $tokenResponse = Http::asForm()
            ->withBasicAuth(config('services.zoom.oauth_client_id'), config('services.zoom.oauth_client_secret'))
            ->post('https://zoom.us/oauth/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => route('zoom.oauth.callback'),
            ])
            ->throw()
            ->json();

        $profile = Http::withToken($tokenResponse['access_token'])
            ->get('https://api.zoom.us/v2/users/me')
            ->throw()
            ->json();

        // BelongsToTenant's own creating() hook reads CurrentTenant to fill
        // tenant_id — nothing to resolve it from on this host-agnostic
        // route, so it's bound explicitly first, same as
        // GenerateLessonSubtitles' own queued-listener precedent.
        app(CurrentTenant::class)->set($tenant);

        InstituteZoomConnection::updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'account_id' => $profile['account_id'],
                'access_token' => $tokenResponse['access_token'],
                'refresh_token' => $tokenResponse['refresh_token'],
                'token_expires_at' => now()->addSeconds((int) $tokenResponse['expires_in']),
                'connected_by_user_id' => $userId,
            ],
        );

        // panel: 'admin' explicitly — this controller runs on a
        // host-agnostic route with no Filament panel context of its own to
        // infer from, and getUrl() defaults to guessing the wrong one
        // (Filament::getPanel(null) picked up the super panel here, since
        // that panel has no domain constraint of its own to rule it out).
        return redirect()->to(ZoomConnection::getUrl(['subdomain' => $tenant->subdomain], panel: 'admin'));
    }
}
