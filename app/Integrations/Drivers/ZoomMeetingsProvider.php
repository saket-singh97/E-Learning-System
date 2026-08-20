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
 * Reads the stored access_token as-is — refresh-before-an-expired-call
 * (token_expires_at vs now, via refresh_token) is B11's own scope, not
 * built here yet, same "this block ships it, the next completes it" order
 * B4/B5 already established for this slice.
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

        return $connection;
    }
}
