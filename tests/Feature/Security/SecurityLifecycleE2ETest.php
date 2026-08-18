<?php

use App\Domains\Security\Models\DeviceSession;
use App\Domains\Security\Models\LoginHistory;
use App\Domains\Security\Services\DeviceFingerprintService;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\DeviceLimitExceededException;
use App\Http\Middleware\EnsureDeviceNotRevoked;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

/**
 * S12 B14 — the one thing no per-block test could cover: B1 (device limit),
 * B2 (remote logout), B3 (impossible-travel) chained in one real run, same
 * "individual pieces are all unit-tested, but nothing proves they still fit
 * together" posture S11 B16's own `SubscriptionLifecycleE2ETest` already
 * established for this app. Dispatches real `Illuminate\Auth\Events\Login`
 * events via `Auth::login()` (not `actingAs()`, which never fires it) so
 * both `RegisterDeviceOnLogin` and `RecordLoginHistory` genuinely run in
 * their real registration order — request bound into the container per
 * login attempt rather than a full HTTP dispatch, the same deliberate
 * workaround B2's own `EnsureDeviceNotRevokedTest` already documents for
 * why a real HTTP client can't reliably round-trip this app's custom device
 * cookie.
 */
function e2eLoginRequest(string $deviceId, string $ip, string $userAgent = 'TestAgent/1.0'): Request
{
    $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => $ip]);
    $request->headers->set('User-Agent', $userAgent);
    $request->cookies->set(DeviceFingerprintService::COOKIE_NAME, $deviceId);
    $request->setLaravelSession(app('session.store'));

    return $request;
}

function loginFrom(User $user, string $deviceId, string $ip): void
{
    app()->instance('request', e2eLoginRequest($deviceId, $ip));
    Auth::login($user);
}

it('runs the full login -> device-limit-hit -> remote-logout -> login-history-flagged chain', function () {
    config(['security.device_limit' => 2]);

    $tenant = Tenant::factory()->create();
    $student = User::factory()->create(['tenant_id' => $tenant->id]);

    // 1. Login — device A, Mumbai. First-ever login: registered, never flagged.
    loginFrom($student, 'device-a', '203.0.113.10');

    expect(Auth::check())->toBeTrue()
        ->and(DeviceSession::withoutGlobalScope('tenant')->where('user_id', $student->id)->count())->toBe(1)
        ->and(LoginHistory::withoutGlobalScope('tenant')->where('user_id', $student->id)->sole()->flagged_impossible_travel)->toBeFalse();

    // A second, distinct device 2 hours later — still within the limit, and
    // Mumbai -> Pune (~150km) in 2 hours is a plausible, unflagged trip.
    $this->travel(2)->hours();
    loginFrom($student, 'device-b', '203.0.113.40');

    expect(Auth::check())->toBeTrue()
        ->and(DeviceSession::withoutGlobalScope('tenant')->where('user_id', $student->id)->count())->toBe(2)
        ->and(LoginHistory::withoutGlobalScope('tenant')->where('user_id', $student->id)->count())->toBe(2);

    // 2. Device-limit-hit — a third, genuinely new device is refused. The
    // half-established session RegisterDeviceOnLogin already tore down
    // (design doc §4 decision 2) means no third device_sessions row and no
    // third login_history row either — the rejected attempt never reaches
    // RecordLoginHistory, which is registered after it (AppServiceProvider).
    expect(fn () => loginFrom($student, 'device-c', '203.0.113.30'))
        ->toThrow(DeviceLimitExceededException::class);

    expect(Auth::check())->toBeFalse()
        ->and(DeviceSession::withoutGlobalScope('tenant')->where('user_id', $student->id)->count())->toBe(2)
        ->and(LoginHistory::withoutGlobalScope('tenant')->where('user_id', $student->id)->count())->toBe(2);

    // 3. Remote-logout — an admin (or the user, from another device) revokes
    // device A via the same action MyDevices's own table row exposes.
    $fingerprintA = app(DeviceFingerprintService::class)->fingerprintFor(e2eLoginRequest('device-a', '203.0.113.10'));
    $deviceA = DeviceSession::withoutGlobalScope('tenant')
        ->where('user_id', $student->id)
        ->where('device_fingerprint', $fingerprintA)
        ->sole();
    $deviceA->update(['revoked_at' => now()]);

    // Device A's browser still thinks it's logged in (its own session was
    // never touched) — its *next* request is what discovers the revocation,
    // the lazy-check tradeoff design doc §4 decision 2 explicitly accepts.
    $this->actingAs($student);
    $requestFromDeviceA = e2eLoginRequest('device-a', '203.0.113.10');

    expect(fn () => (new EnsureDeviceNotRevoked)->handle($requestFromDeviceA, fn ($req) => response('passed')))
        ->toThrow(HttpException::class);

    expect(Auth::check())->toBeFalse();

    // 4. Login-history-flagged — device B (already registered, so no limit
    // check fires) logs in again 3 minutes later from New York. Compared
    // against its own immediately-preceding Pune entry, that implied speed
    // is far beyond the plausible-travel threshold.
    $this->travel(3)->minutes();
    loginFrom($student, 'device-b', '203.0.113.20');

    $rows = LoginHistory::withoutGlobalScope('tenant')->where('user_id', $student->id)->orderBy('id')->get();
    expect($rows)->toHaveCount(3)
        ->and($rows[0]->flagged_impossible_travel)->toBeFalse() // Mumbai, first-ever
        ->and($rows[1]->flagged_impossible_travel)->toBeFalse() // Pune, plausible from Mumbai
        ->and($rows[2]->flagged_impossible_travel)->toBeTrue(); // New York, impossible from Pune in 3 minutes

    // Device B merely refreshed its own row — still 2 devices, not 3, and
    // device A's revocation is untouched by any of this.
    expect(DeviceSession::withoutGlobalScope('tenant')->where('user_id', $student->id)->count())->toBe(2)
        ->and($deviceA->fresh()->isRevoked())->toBeTrue();
});
