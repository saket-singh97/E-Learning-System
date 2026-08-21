<?php

namespace App\Integrations\Drivers;

use App\Domains\Batches\Models\BatchSession;
use App\Integrations\LiveClassProvider;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Real Google Calendar API driver (G8): a Meet link comes from creating a
 * Calendar event with `conferenceData` — Meet has no standalone "create a
 * room" API of its own. Platform-wide credential, not per-institute like
 * ZoomMeetingsProvider (G7) — no institute_google_connections table exists,
 * same "one shared credential" posture ZoomVideoSdkProvider's own SDK
 * key/secret already established, except here it's an OAuth refresh_token
 * (Calendar API needs a real Google account behind it, not a signed JWT)
 * exchanged for a fresh access_token on every call, never cached (mirrors
 * ZoomOAuthController's own token-exchange shape).
 *
 * Google Meet has no host/participant URL split the way Zoom's start_url/
 * join_url does — every guest on the calendar event gets the same
 * hangoutLink, so joinUrl() returns it unconditionally (same posture
 * ExternalLinkProvider already takes). Access control is therefore Google's
 * own meeting lobby, not this app's role check — a documented limitation,
 * not a gap this driver can close.
 */
class GoogleMeetProvider implements LiveClassProvider
{
    public function createRoom(BatchSession $session): string
    {
        $accessToken = $this->accessToken();
        $calendarId = config('services.google.calendar_id', 'primary');

        $response = Http::withToken($accessToken)
            ->post("https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events?conferenceDataVersion=1", [
                'summary' => $session->title ?: "Live session #{$session->id}",
                'start' => ['dateTime' => $session->starts_at->toRfc3339String()],
                'end' => ['dateTime' => $session->starts_at->clone()->addMinutes($session->duration_minutes)->toRfc3339String()],
                'conferenceData' => [
                    'createRequest' => [
                        'requestId' => (string) Str::uuid(),
                        'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                    ],
                ],
            ])
            ->throw()
            ->json();

        return (string) $response['hangoutLink'];
    }

    public function joinUrl(BatchSession $session, User $viewer): string
    {
        return $session->provider_meeting_ref ?? $this->createRoom($session);
    }

    public function markEnded(BatchSession $session): void
    {
        // Calendar API has no "end this meeting now" call — the event's own
        // end time passes naturally and Meet closes itself, same posture
        // ZoomVideoSdkProvider's own markEnded() already takes.
    }

    /**
     * Exchanges the platform-wide refresh_token for a fresh access_token on
     * every call rather than storing/reusing one — no schema exists to
     * persist it, and this is a rare admin-scheduling action, not a hot
     * path, so the extra round trip is a non-issue.
     */
    protected function accessToken(): string
    {
        $refreshToken = config('services.google.calendar_refresh_token');

        if (blank($refreshToken)) {
            throw new RuntimeException(
                'Google Calendar credentials are not configured (GOOGLE_CALENDAR_REFRESH_TOKEN) — '.
                'connect a platform Google account with Calendar access first.'
            );
        }

        $response = Http::asForm()
            ->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'refresh_token',
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'refresh_token' => $refreshToken,
            ])
            ->throw()
            ->json();

        return (string) $response['access_token'];
    }
}
