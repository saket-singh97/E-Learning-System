<?php

namespace App\Integrations\Drivers;

use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\InstituteZoomConnection;
use App\Integrations\LiveClassProvider;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Real Zoom Meetings REST API driver (G7 pt.2, design doc decision 1) —
 * distinct from ZoomVideoSdkProvider: that one signs a client-side JWT
 * against one platform-wide SDK key; this one authenticates as the
 * connected institute's own OAuth token (B6, InstituteZoomConnection) and
 * calls Zoom's real Meetings API. Http::fake()-tested, no real account,
 * same precedent RazorpayProviderTest already uses.
 *
 * B11 (G10 pt.2): connectionFor() refreshes an expired access_token via the
 * stored refresh_token before handing the connection back, same
 * grant_type=refresh_token/withBasicAuth(client_id, client_secret) shape
 * ZoomOAuthController::callback() already uses for the initial exchange.
 * Every public method routes through connectionFor(), so no call site can
 * accidentally use a stale token.
 */
class ZoomMeetingsProvider implements LiveClassProvider
{
    /** @var array<int, string> Roles Zoom's start_url (host) goes to, not the participant join_url. */
    public const HOST_ROLES = ['instructor', 'institute_admin'];

    public function createRoom(BatchSession $session): string
    {
        $connection = $this->connectionFor($session);

        $response = Http::withToken($connection->access_token)
            ->post('https://api.zoom.us/v2/users/me/meetings', [
                'topic' => $session->title ?: "Live session #{$session->id}",
                'type' => 2, // scheduled meeting
                'start_time' => $session->starts_at->toIso8601String(),
                'duration' => $session->duration_minutes,
            ])
            ->throw()
            ->json();

        return (string) $response['id'];
    }

    public function joinUrl(BatchSession $session, User $viewer): string
    {
        $connection = $this->connectionFor($session);
        $meetingId = $session->provider_meeting_ref ?? $this->createRoom($session);

        $meeting = Http::withToken($connection->access_token)
            ->get("https://api.zoom.us/v2/meetings/{$meetingId}")
            ->throw()
            ->json();

        return $viewer->hasAnyRole(self::HOST_ROLES) ? $meeting['start_url'] : $meeting['join_url'];
    }

    public function markEnded(BatchSession $session): void
    {
        if ($session->provider_meeting_ref === null) {
            return;
        }

        $connection = $this->connectionFor($session);

        // Best-effort: a meeting Zoom already ended on its own (or one that
        // never actually started) 404s/409s here — nothing left to clean up
        // either way, so the response isn't checked.
        Http::withToken($connection->access_token)
            ->put("https://api.zoom.us/v2/meetings/{$session->provider_meeting_ref}/status", ['action' => 'end']);
    }

    protected function connectionFor(BatchSession $session): InstituteZoomConnection
    {
        $connection = InstituteZoomConnection::where('tenant_id', $session->tenant_id)->first();

        if ($connection === null) {
            throw new RuntimeException("Tenant [{$session->tenant_id}] has no connected Zoom account — connect one in the Admin panel first.");
        }

        return $this->refreshIfExpired($connection);
    }

    /**
     * Zoom access tokens are short-lived (1 hour); refresh_token is
     * long-lived and rotates on every use, so the new one is stored right
     * alongside the new access_token — reusing an already-rotated
     * refresh_token would otherwise fail the next refresh.
     */
    protected function refreshIfExpired(InstituteZoomConnection $connection): InstituteZoomConnection
    {
        if (! $connection->token_expires_at?->isPast()) {
            return $connection;
        }

        $tokenResponse = Http::asForm()
            ->withBasicAuth(config('services.zoom.oauth_client_id'), config('services.zoom.oauth_client_secret'))
            ->post('https://zoom.us/oauth/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $connection->refresh_token,
            ])
            ->throw()
            ->json();

        $connection->update([
            'access_token' => $tokenResponse['access_token'],
            'refresh_token' => $tokenResponse['refresh_token'],
            'token_expires_at' => now()->addSeconds((int) $tokenResponse['expires_in']),
        ]);

        return $connection->fresh();
    }
}
