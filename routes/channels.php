<?php

use App\Domains\Comms\Models\ForumThread;
use App\Domains\Comms\Services\ForumThreadAccess;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * S19 N11 — the one shared private channel every subsequent real-time
 * feature this slice adds (direct messages B1, forum posts B3, help-desk
 * ticket replies B9) authorizes against. A tenant boundary needs no
 * separate check here: user ids aren't guessable/shared across tenants,
 * and this closure only ever matches the literal authenticated user, so a
 * cross-tenant subscribe attempt is refused by the id mismatch alone, same
 * as `App.Models.User.{id}` above.
 */
Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * S19 N14 pt.2 — one shared channel for both BatchForum and GeneralForum
 * threads (a thread's own `batch_id` decides which rule applies, not two
 * separate channel patterns). Fetched `withoutGlobalScopes()`: this
 * endpoint carries no `Route::domain()` constraint (B0), so `CurrentTenant`
 * isn't guaranteed bound the way a normal tenant-subdomain request would —
 * `ForumThreadAccess::canParticipateInThread()`'s own explicit
 * `$viewer->tenant_id !== $thread->tenant_id` check is the real boundary
 * here, not the model's global scope.
 */
Broadcast::channel('forum-thread.{threadId}', function ($user, $threadId) {
    $thread = ForumThread::withoutGlobalScopes()->find($threadId);

    return $thread !== null && ForumThreadAccess::canParticipateInThread($thread, $user);
});
