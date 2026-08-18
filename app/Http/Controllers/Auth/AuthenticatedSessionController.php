<?php

namespace App\Http\Controllers\Auth;

use App\Domains\Tenancy\Services\TenantAuthenticator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthenticatedSessionController extends Controller
{
    public function store(LoginRequest $request): JsonResponse
    {
        $user = $request->authenticate();

        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put([
                'two_factor.pending_user_id' => $user->id,
                'two_factor.remember' => $request->boolean('remember'),
            ]);

            return response()->json(['two_factor_required' => true]);
        }

        Auth::login($user, $request->boolean('remember'));

        $request->session()->regenerate();

        return response()->json(['id' => Auth::id(), 'email' => Auth::user()->email]);
    }

    /**
     * Second step for a user whose store() call above returned
     * two_factor_required — reads the pending user id back out of the
     * session (set above), same "validate first, log in only after the
     * code also passes" shape every other surface in this app now follows.
     */
    public function storeTwoFactorChallenge(TwoFactorChallengeRequest $request): JsonResponse
    {
        $userId = $request->session()->get('two_factor.pending_user_id');

        abort_unless($userId, 419);

        $user = User::findOrFail($userId);

        app(TenantAuthenticator::class)->challengeTwoFactor($user, $request->string('code')->value());

        Auth::login($user, (bool) $request->session()->get('two_factor.remember', false));

        $request->session()->forget(['two_factor.pending_user_id', 'two_factor.remember']);
        $request->session()->regenerate();

        return response()->json(['id' => Auth::id(), 'email' => Auth::user()->email]);
    }

    public function destroy(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(status: 204);
    }
}
