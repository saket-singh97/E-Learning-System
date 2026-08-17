<?php

namespace App\Domains\Security\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Design doc §4 decision 1 — a hash of a stable first-party cookie ID plus
 * user-agent, not a paid fingerprinting vendor (no budget signal anywhere
 * in this app for one, and this is the same precision level most
 * session-limit implementations actually use). A cleared cookie resets the
 * fingerprint — a real, stated limitation, not glossed over.
 */
class DeviceFingerprintService
{
    public const COOKIE_NAME = 'lms_device_id';

    /**
     * 2 years — deliberately longer than "remember me"'s own 30 days
     * (FEATURES.md B4, a session-persistence concern). This cookie is a
     * device identity marker, not an auth token; it should outlive any one
     * login session.
     */
    private const COOKIE_MINUTES = 60 * 24 * 730;

    /**
     * Reads the existing device-id cookie if present, otherwise mints one
     * and queues it onto the response (`Cookie::queue()` — the standard
     * Laravel mechanism for attaching a cookie from anywhere in the request
     * lifecycle, including an event listener that runs after the
     * controller has already built its own response). httpOnly so client
     * JS can't read or forge it.
     */
    public function fingerprintFor(Request $request): string
    {
        $deviceId = $request->cookie(self::COOKIE_NAME);

        if (! is_string($deviceId) || $deviceId === '') {
            $deviceId = (string) Str::uuid();

            Cookie::queue(Cookie::make(
                name: self::COOKIE_NAME,
                value: $deviceId,
                minutes: self::COOKIE_MINUTES,
                httpOnly: true,
                sameSite: 'lax',
            ));
        }

        return hash('sha256', $deviceId.'|'.$request->userAgent());
    }
}
