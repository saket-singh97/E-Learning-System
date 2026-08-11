<?php

namespace App\Integrations\Drivers;

use App\Domains\Batches\Models\BatchSession;
use App\Integrations\LiveClassProvider;
use App\Models\User;
use InvalidArgumentException;

/**
 * Real, zero-setup driver: the "room" is whatever URL the teacher pasted
 * into the scheduling form (BatchSession::provider_meeting_ref). No vendor
 * account, no outbound call — createRoom() just validates the URL is there
 * and passes it through unchanged.
 */
class ExternalLinkProvider implements LiveClassProvider
{
    public function createRoom(BatchSession $session): string
    {
        if (blank($session->provider_meeting_ref)) {
            throw new InvalidArgumentException(
                "BatchSession #{$session->id} has no meeting URL to create an external-link room from."
            );
        }

        return $session->provider_meeting_ref;
    }

    public function joinUrl(BatchSession $session, User $viewer): string
    {
        return (string) $session->provider_meeting_ref;
    }

    public function markEnded(BatchSession $session): void
    {
        // Plain link has no vendor-side state to tear down.
    }
}
