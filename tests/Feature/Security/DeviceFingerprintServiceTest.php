<?php

use App\Domains\Security\Services\DeviceFingerprintService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

it('mints a new device-id cookie and queues it when none exists yet', function () {
    $request = Request::create('/login', 'POST');
    $request->headers->set('User-Agent', 'TestAgent/1.0');

    $fingerprint = app(DeviceFingerprintService::class)->fingerprintFor($request);

    expect($fingerprint)->toBeString()->toHaveLength(64)
        ->and(Cookie::hasQueued(DeviceFingerprintService::COOKIE_NAME))->toBeTrue();
});

it('reuses an existing device-id cookie without queueing a new one', function () {
    $request = Request::create('/login', 'POST');
    $request->headers->set('User-Agent', 'TestAgent/1.0');
    $request->cookies->set(DeviceFingerprintService::COOKIE_NAME, 'already-set-device-id');

    app(DeviceFingerprintService::class)->fingerprintFor($request);

    expect(Cookie::hasQueued(DeviceFingerprintService::COOKIE_NAME))->toBeFalse();
});

it('produces the same fingerprint for the same cookie + user agent, and a different one for a different user agent', function () {
    $service = app(DeviceFingerprintService::class);

    $requestA = Request::create('/login', 'POST');
    $requestA->headers->set('User-Agent', 'TestAgent/1.0');
    $requestA->cookies->set(DeviceFingerprintService::COOKIE_NAME, 'same-device-id');

    $requestB = Request::create('/login', 'POST');
    $requestB->headers->set('User-Agent', 'TestAgent/1.0');
    $requestB->cookies->set(DeviceFingerprintService::COOKIE_NAME, 'same-device-id');

    $requestC = Request::create('/login', 'POST');
    $requestC->headers->set('User-Agent', 'DifferentAgent/2.0');
    $requestC->cookies->set(DeviceFingerprintService::COOKIE_NAME, 'same-device-id');

    expect($service->fingerprintFor($requestA))
        ->toBe($service->fingerprintFor($requestB))
        ->not->toBe($service->fingerprintFor($requestC));
});
