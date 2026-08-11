<?php

namespace App\Integrations;

use App\Domains\Batches\Models\BatchSession;
use App\Models\User;

interface LiveClassProvider
{
    /**
     * Create (or return existing) the room for this session; returns the
     * opaque ref to store on BatchSession::provider_meeting_ref.
     */
    public function createRoom(BatchSession $session): string;

    /**
     * Resolve the URL/credentials this specific viewer uses to join right
     * now. Never stored — resolved fresh per call.
     */
    public function joinUrl(BatchSession $session, User $viewer): string;

    /**
     * Best-effort cleanup hook once a session's window has passed.
     */
    public function markEnded(BatchSession $session): void;
}
