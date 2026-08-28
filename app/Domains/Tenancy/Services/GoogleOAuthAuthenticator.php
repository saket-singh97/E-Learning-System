<?php

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\GoogleWorkspaceDomainMismatchException;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * The one place "a Google identity resolved to which User row" is decided —
 * same "one shared path" posture TenantAuthenticator holds for password
 * login. Google OAuth carries no subdomain of its own, so the tenant a new
 * or existing user resolves against is whichever tenant the redirect step
 * (GoogleAuthController::redirect(), still running on that tenant's own
 * subdomain) remembered in the session — passed in here as the resolved
 * Tenant rather than read from CurrentTenant, since by the time the
 * callback request arrives there is no subdomain left to resolve it from.
 */
class GoogleOAuthAuthenticator
{
    /**
     * S21 B7 (V13a) — checked first, before any user lookup: an unconfigured
     * tenant (`google_workspace_domain` null) behaves byte-identical to
     * before this block, any Google account may sign in. Configured, only
     * an account on that domain may authenticate for this tenant at all —
     * checked ahead of the google_id/email lookups below so a mismatched
     * domain can never even reach (and thus never silently link onto) an
     * existing account.
     *
     * Match by google_id first (returning user who already linked this
     * Google account). Falling back to an email match within the same
     * tenant handles a user who registered with a password first and is
     * now trying Google for the first time — the two identities converge
     * onto the one existing row rather than creating a duplicate account,
     * and google_id gets linked onto it so the next sign-in matches
     * directly. No match at all creates a brand new row, the same
     * first-touch shape RegisteredUserController already uses.
     */
    public function authenticate(SocialiteUser $socialiteUser, Tenant $tenant): User
    {
        if ($tenant->google_workspace_domain !== null) {
            $emailDomain = Str::after($socialiteUser->getEmail(), '@');

            if (strcasecmp($emailDomain, $tenant->google_workspace_domain) !== 0) {
                throw GoogleWorkspaceDomainMismatchException::make($tenant->google_workspace_domain);
            }
        }

        $tenantId = $tenant->id;

        $user = User::where('tenant_id', $tenantId)
            ->where('google_id', $socialiteUser->getId())
            ->first();

        if ($user !== null) {
            return $user;
        }

        $user = User::where('tenant_id', $tenantId)
            ->where('email', $socialiteUser->getEmail())
            ->first();

        if ($user !== null) {
            $user->update(['google_id' => $socialiteUser->getId()]);

            return $user;
        }

        $user = User::create([
            'tenant_id' => $tenantId,
            'name' => $socialiteUser->getName() ?? $socialiteUser->getEmail(),
            'email' => $socialiteUser->getEmail(),
            'google_id' => $socialiteUser->getId(),
            // users.password is NOT NULL and this account never uses it —
            // a random value it can never type keeps the column satisfied
            // without meaning anything.
            'password' => Str::random(40),
        ]);

        // Google has already verified this email address — that's inherent
        // to receiving it via OAuth — so there is nothing for
        // RegisteredUserController's own sendEmailVerificationNotification()
        // step to confirm here.
        $user->markEmailAsVerified();

        return $user;
    }
}
