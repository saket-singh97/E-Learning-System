<?php

namespace App\Integrations\Drivers;

use App\Domains\Batches\Models\BatchSession;
use App\Integrations\LiveClassProvider;
use App\Models\User;
use RuntimeException;

/**
 * Real driver, unit-testable without a live Zoom account: the join contract
 * is a JWT signed with the institute's SDK key/secret, not an outbound API
 * call (design doc §2.4 decision 3, §3.2). No firebase/php-jwt dependency —
 * HS256 is the entire spec Zoom's Video SDK asks for, so it's built by hand
 * below rather than pulling in a library for one algorithm.
 *
 * Not the bound LiveClassProvider yet: AppServiceProvider still binds
 * FakeLiveClassDriver, since no real ZOOM_SDK_KEY/SECRET exist. A
 * BatchSession whose live_provider is 'zoom_video_sdk' resolves through
 * that same container binding (BatchSession::resolveLiveClassProvider()),
 * not this class directly — so picking Zoom in the scheduling form today
 * safely degrades to the Fake driver instead of throwing on missing
 * credentials. This class exists so the JWT-signing half is real and
 * tested now, ready for AppServiceProvider to bind once credentials land.
 */
class ZoomVideoSdkProvider implements LiveClassProvider
{
    /** @var array<int, string> Roles that map to the SDK's role_type=1 (host/moderator) claim. */
    public const HOST_ROLES = ['instructor', 'institute_admin'];

    /**
     * No outbound call: a Zoom Video SDK session only needs a stable topic
     * name up front. The session itself is created client-side once a
     * signed JWT authorises the join.
     */
    public function createRoom(BatchSession $session): string
    {
        return "lms-session-{$session->id}";
    }

    public function joinUrl(BatchSession $session, User $viewer): string
    {
        $topic = $session->provider_meeting_ref ?? $this->createRoom($session);
        $roleType = $viewer->hasAnyRole(self::HOST_ROLES) ? 1 : 0;

        return $this->signSessionToken($topic, $roleType);
    }

    public function markEnded(BatchSession $session): void
    {
        // No vendor-side call needed — each participant's JWT already
        // carries its own short expiry, so the session ends itself.
    }

    /**
     * Zoom Video SDK's session-authentication JWT: HS256 over
     * {app_key, tpc, role_type, version, iat, exp}, signed with the SDK
     * secret. Tokens are minted fresh per call and never stored — a stale
     * one is simply left to expire, never reused (design doc §3.2).
     */
    protected function signSessionToken(string $topic, int $roleType): string
    {
        $sdkKey = config('services.zoom.sdk_key');
        $sdkSecret = config('services.zoom.sdk_secret');

        if (blank($sdkKey) || blank($sdkSecret)) {
            throw new RuntimeException(
                'Zoom Video SDK credentials are not configured (ZOOM_SDK_KEY / ZOOM_SDK_SECRET) — '.
                'this is exactly why it is not yet the bound LiveClassProvider.'
            );
        }

        $issuedAt = now()->timestamp;

        return $this->encode([
            'app_key' => $sdkKey,
            'tpc' => $topic,
            'role_type' => $roleType,
            'version' => 1,
            'iat' => $issuedAt,
            'exp' => $issuedAt + 7200, // 2-hour join token, well under Zoom's 48h cap
        ], $sdkSecret);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function encode(array $payload, string $secret): string
    {
        $segments = [
            $this->base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)),
            $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR)),
        ];

        $signature = hash_hmac('sha256', implode('.', $segments), $secret, true);
        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
