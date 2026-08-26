<?php

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
