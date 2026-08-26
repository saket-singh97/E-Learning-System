<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * S19 N11 — `routes/channels.php`'s `user.{id}` private channel is the one
 * shared real-time channel every later block (B1 direct messages, B3 forum
 * posts, B9 help-desk replies) authorizes against. The auth endpoint
 * (`/broadcasting/auth`, wired via `bootstrap/app.php`'s `channels:` routing
 * param) has no `Route::domain()` wrapper — same shape Livewire's own
 * `/livewire/update` endpoint takes (CLAUDE.md) — so these tests hit it
 * directly, not through a tenant subdomain.
 *
 * `broadcasting.default` is forced to `reverb` here only: phpunit.xml's own
 * `null` test default resolves `NullBroadcaster`, whose `auth()` is a no-op
 * that never invokes a registered channel callback at all — this override
 * is what actually exercises `channels.php`'s closure through the real
 * Pusher-protocol auth flow Reverb speaks (a local HMAC computation, no live
 * socket connection needed). `routes/channels.php` itself only runs once, at
 * boot, against whatever driver was default *then* (`null`, in tests) — its
 * `Broadcast::channel()` calls populate that specific driver instance's own
 * registry, not a driver switched to afterward, so switching the config
 * alone leaves the freshly-resolved `reverb` driver's registry empty.
 * Re-requiring the routes file after the switch re-registers the same
 * channels against the now-current default — test-only, since production
 * never switches this at runtime.
 */
beforeEach(function () {
    config(['broadcasting.default' => 'reverb']);
    require base_path('routes/channels.php');
});

it('authorizes a user for their own private channel', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/broadcasting/auth', [
            'channel_name' => 'private-user.'.$user->id,
            'socket_id' => '1234.1234',
        ])
        ->assertOk();
});

it('refuses a user requesting a different user\'s private channel', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/broadcasting/auth', [
            'channel_name' => 'private-user.'.$other->id,
            'socket_id' => '1234.1234',
        ])
        ->assertForbidden();
});

it('refuses an unauthenticated request', function () {
    $user = User::factory()->create();

    $this->postJson('/broadcasting/auth', [
        'channel_name' => 'private-user.'.$user->id,
        'socket_id' => '1234.1234',
    ])->assertForbidden();
});
