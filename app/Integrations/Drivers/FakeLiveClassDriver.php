<?php

namespace App\Integrations\Drivers;

use App\Domains\Batches\Models\BatchSession;
use App\Integrations\LiveClassProvider;
use App\Models\User;

/**
 * Deterministic, hash-seeded fake — same session always resolves to the
 * same room ref, so tests stay reproducible without a real vendor account.
 * Real drivers (ExternalLinkProvider, ZoomVideoSdkProvider) are S5 B1/B4;
 * every call site here is written against the interface only.
 */
class FakeLiveClassDriver implements LiveClassProvider
{
    public function createRoom(BatchSession $session): string
    {
        return 'fake-room-'.dechex(crc32("session#{$session->id}"));
    }

    public function joinUrl(BatchSession $session, User $viewer): string
    {
        $ref = $session->provider_meeting_ref ?? $this->createRoom($session);

        return "https://fake-live.test/{$ref}/join?user={$viewer->id}";
    }

    public function markEnded(BatchSession $session): void
    {
        // Deterministic fake — no external state to tear down.
    }
}
