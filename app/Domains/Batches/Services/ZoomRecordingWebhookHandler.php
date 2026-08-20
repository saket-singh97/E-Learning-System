<?php

namespace App\Domains\Batches\Services;

use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\LiveRecording;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use RuntimeException;

/**
 * G5 pt.1 / G10 pt.1 (design doc decision 3) — verifies Zoom's own
 * HMAC-SHA256 webhook signature (mirrors
 * `PaymentProvider::verifyWebhookSignature()`'s established shape) and
 * records a `pending` LiveRecording row for a `recording.completed`
 * event. Downloading the file itself is B9's own `DownloadLiveRecording`
 * job — not dispatched from here yet, same "this block ships it, the
 * next completes it" order B4/B5/B7 already established for this slice.
 */
class ZoomRecordingWebhookHandler
{
    /**
     * Zoom's real scheme: HMAC-SHA256 over "v0:{timestamp}:{raw body}",
     * hex-encoded and prefixed "v0=", compared against the
     * x-zm-signature header.
     */
    public function verifySignature(string $payload, string $timestamp, string $signature): bool
    {
        $secret = config('services.zoom.webhook_secret');

        if (blank($secret)) {
            throw new RuntimeException(
                'Zoom webhook secret is not configured (ZOOM_WEBHOOK_SECRET) — '.
                'this is exactly why this endpoint is not yet reachable in production.'
            );
        }

        $expected = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$payload}", $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Zoom's own one-time handshake (`endpoint.url_validation`), sent
     * unsigned before the app is verified — echoed back hashed with the
     * webhook secret, no recording activity involved.
     */
    public function encryptedToken(string $plainToken): string
    {
        return hash_hmac('sha256', $plainToken, (string) config('services.zoom.webhook_secret'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleRecordingCompleted(array $payload): void
    {
        $meetingId = (string) ($payload['payload']['object']['id'] ?? '');
        $recordingId = (string) ($payload['payload']['object']['uuid'] ?? '');

        // No CurrentTenant bound on this host-agnostic route — the tenant
        // global scope skips filtering while unbound (BelongsToTenant), so
        // this genuinely searches across every tenant for the one
        // BatchSession this meeting belongs to.
        $session = BatchSession::where('provider_meeting_ref', $meetingId)->first();

        if ($session === null) {
            return;
        }

        app(CurrentTenant::class)->set(Tenant::findOrFail($session->tenant_id));

        LiveRecording::create([
            'batch_session_id' => $session->id,
            'provider_recording_id' => $recordingId,
            'status' => 'pending',
        ]);
    }
}
