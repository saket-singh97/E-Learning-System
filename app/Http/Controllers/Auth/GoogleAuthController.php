<?php

namespace App\Http\Controllers\Auth;

use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\GoogleOAuthAuthenticator;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

/**
 * Scoped to the two portals (student, parent) — the surfaces a real
 * end-user is most likely signing into with a personal Google account. The
 * three Filament panels already got TenantAuthenticator's own rate-limited
 * password login plus optional TOTP (S9 B1); adding Google OAuth there too
 * is a fast-follow, not attempted here.
 *
 * redirect() runs on the tenant's own subdomain (Route::domain(...), same
 * as every other portal route), so CurrentTenant is resolved normally. The
 * Google redirect_uri Google itself calls back to must be one fixed,
 * pre-registered URL — it cannot vary per subdomain — so callback() is a
 * host-agnostic route (routes/web.php) with no domain constraint at all.
 * That means CurrentTenant has nothing to resolve from by the time the
 * callback request arrives; the tenant id and which portal initiated the
 * flow are carried across in the session instead (SESSION_DOMAIN is a
 * shared parent domain, so a session started on physics.lms.test is still
 * readable on the bare callback host).
 */
class GoogleAuthController extends Controller
{
    /**
     * The portal segment is read off the route rather than method-injected
     * — same domain-route splicing gotcha as StreamLessonController/
     * WatchLessonController (CLAUDE.md): a scalar $portal parameter on a
     * Route::domain('{subdomain}.lms.test') route risks receiving the
     * subdomain value instead once request-scoped resolution kicks in.
     */
    public function redirect(Request $request): RedirectResponse
    {
        $portal = $request->route('portal');

        abort_unless(in_array($portal, ['student', 'parent'], true), 404);

        session([
            'oauth.tenant_id' => app(CurrentTenant::class)->id(),
            'oauth.portal' => $portal,
        ]);

        return Socialite::driver('google')
            ->redirectUrl(route('auth.google.callback'))
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $tenantId = $request->session()->pull('oauth.tenant_id');
        $portal = $request->session()->pull('oauth.portal', 'student');

        abort_unless($tenantId, 419);

        $tenant = Tenant::findOrFail($tenantId);

        $socialiteUser = Socialite::driver('google')
            ->redirectUrl(route('auth.google.callback'))
            ->user();

        $user = app(GoogleOAuthAuthenticator::class)->authenticate($socialiteUser, $tenantId);

        $routeName = $portal === 'parent' ? 'parent.dashboard' : 'student.dashboard';

        // Found bypassed entirely by the S9 B14 review pass: this path
        // used to call Auth::login($user) unconditionally, so a user with
        // TOTP enabled (S9 B1) had it silently skipped just by using the
        // Google button instead of the password form — Google proves
        // identity, not possession of the second factor, and
        // StudentPortal/ParentPortal's own password login already treats
        // those as two separate steps. Handed off the same way: stash the
        // pending user id, let the portal component's own mount() pick up
        // the existing two-factor challenge UI rather than building a
        // second one here.
        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put('oauth.pending_two_factor_user_id', $user->id);

            return redirect()->to(route($routeName, ['subdomain' => $tenant->subdomain]));
        }

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()->to(route($routeName, ['subdomain' => $tenant->subdomain]));
    }
}
