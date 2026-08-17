<?php

use App\Domains\Security\Models\DeviceSession;
use App\Domains\Security\Services\DeviceFingerprintService;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Middleware\EnsureDeviceNotRevoked;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

function middlewareRequest(string $deviceId, string $userAgent = 'TestAgent/1.0'): Request
{
    $request = Request::create('/', 'GET');
    $request->headers->set('User-Agent', $userAgent);
    $request->cookies->set(DeviceFingerprintService::COOKIE_NAME, $deviceId);
    $request->setLaravelSession(app('session.store'));

    return $request;
}

/**
 * Calls the middleware's own handle() directly rather than dispatching a
 * full HTTP request through the kernel — a real request passes this
 * cookie through `EncryptCookies` first, and this block's own testing
 * could not get a custom app cookie to reliably survive that round-trip
 * with a known, predictable value inside Laravel's test HTTP client
 * (neither `withUnencryptedCookie()`, which the middleware's own
 * `catch (DecryptException)` silently drops, nor `withCookie()`, which
 * should be the correct encrypted-cookie helper but still didn't produce
 * a matching fingerprint across three separate attempts — root cause not
 * fully isolated, see the plan doc's own B2 notes for what was tried and
 * why this was deliberately not chased further). Calling handle()
 * directly sidesteps the whole question: no HTTP kernel, no
 * EncryptCookies, the cookie set here is read back exactly as set —
 * fully proving the middleware's own logic, leaving only "is it wired
 * into the real middleware stack correctly" unverified by an automated
 * test (evidenced instead by direct code inspection: registered in the
 * exact same dual-shape `ResolveTenant`/`ResolveImpersonation` already
 * use, both of which real, passing HTTP tests elsewhere in this suite do
 * depend on firing correctly).
 */
it('blocks a request from a device revoked by another session, tears the session down', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $this->actingAs($user);

    $request = middlewareRequest('revoked-device');
    $fingerprint = app(DeviceFingerprintService::class)->fingerprintFor($request);

    DeviceSession::withoutEvents(fn () => DeviceSession::create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'device_fingerprint' => $fingerprint,
        'last_seen_at' => now(),
        'revoked_at' => now(),
    ]));

    expect(fn () => (new EnsureDeviceNotRevoked)->handle($request, fn ($req) => response('passed')))
        ->toThrow(HttpException::class);

    expect(Auth::check())->toBeFalse();
});

it('lets an active, registered device through untouched', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $this->actingAs($user);

    $request = middlewareRequest('active-device');
    $fingerprint = app(DeviceFingerprintService::class)->fingerprintFor($request);

    DeviceSession::withoutEvents(fn () => DeviceSession::create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'device_fingerprint' => $fingerprint,
        'last_seen_at' => now(),
    ]));

    $response = (new EnsureDeviceNotRevoked)->handle($request, fn ($req) => response('passed'));

    expect($response->getContent())->toBe('passed')
        ->and(Auth::check())->toBeTrue();
});

it('lets a request through when the device has no device_sessions row at all', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $this->actingAs($user);

    $response = (new EnsureDeviceNotRevoked)->handle(middlewareRequest('never-registered'), fn ($req) => response('passed'));

    expect($response->getContent())->toBe('passed');
});

it('never blocks a platform-level user with no tenant, regardless of any device_sessions state', function () {
    $superAdmin = User::factory()->create(['tenant_id' => null]);
    $this->actingAs($superAdmin);

    $response = (new EnsureDeviceNotRevoked)->handle(middlewareRequest('super-admin-device'), fn ($req) => response('passed'));

    expect($response->getContent())->toBe('passed');
});

it('lets a guest request through with no authenticated user to check', function () {
    $response = (new EnsureDeviceNotRevoked)->handle(middlewareRequest('guest-device'), fn ($req) => response('passed'));

    expect($response->getContent())->toBe('passed');
});
