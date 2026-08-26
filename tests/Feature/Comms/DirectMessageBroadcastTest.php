<?php

use App\Domains\Comms\Events\DirectMessageSent;
use App\Domains\Comms\Livewire\DirectMessages;
use App\Domains\Comms\Models\DirectMessage;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * S19 N11 pt.2 — real-time push for direct messages, over the `user.{id}`
 * private channel B0 already authorizes. `Livewire::test()` never touches a
 * real socket (CLAUDE.md), so these tests cover exactly what's testable
 * without one: the broadcast event fires with the right recipient-scoped
 * channel and payload, and the reconnect-catch-up method returns exactly
 * the delta a dropped socket could have missed.
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->sender = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->recipient = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

it('broadcasts a sent message on the recipient\'s own private channel', function () {
    Event::fake([DirectMessageSent::class]);

    Livewire::actingAs($this->sender)
        ->test(DirectMessages::class)
        ->call('selectConversation', $this->recipient->id)
        ->set('newMessageBody', 'Hello there')
        ->call('send');

    Event::assertDispatched(DirectMessageSent::class, function (DirectMessageSent $event) {
        $channels = $event->broadcastOn();

        return count($channels) === 1
            && (string) $channels[0] === 'private-user.'.$this->recipient->id
            && $event->broadcastAs() === 'message.sent'
            && $event->broadcastWith()['body'] === 'Hello there'
            && $event->broadcastWith()['sender_id'] === $this->sender->id;
    });
});

it('never broadcasts a message on another user\'s channel', function () {
    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    $unrelatedUser = User::factory()->create(['tenant_id' => $otherTenant->id]);

    Event::fake([DirectMessageSent::class]);

    Livewire::actingAs($this->sender)
        ->test(DirectMessages::class)
        ->call('selectConversation', $this->recipient->id)
        ->set('newMessageBody', 'Hello there')
        ->call('send');

    Event::assertDispatched(DirectMessageSent::class, function (DirectMessageSent $event) use ($unrelatedUser) {
        $channelName = (string) $event->broadcastOn()[0];

        return $channelName === 'private-user.'.$this->recipient->id
            && $channelName !== 'private-user.'.$unrelatedUser->id;
    });
});

it('catches up on exactly the messages missed since a given id', function () {
    $first = DirectMessage::create(['sender_id' => $this->recipient->id, 'recipient_id' => $this->sender->id, 'body' => 'one']);
    $second = DirectMessage::create(['sender_id' => $this->recipient->id, 'recipient_id' => $this->sender->id, 'body' => 'two']);
    $third = DirectMessage::create(['sender_id' => $this->recipient->id, 'recipient_id' => $this->sender->id, 'body' => 'three']);

    // A different thread entirely (same sender, different recipient) must
    // never leak into another thread's catch-up.
    $otherRecipient = User::factory()->create(['tenant_id' => $this->tenant->id]);
    DirectMessage::create(['sender_id' => $this->recipient->id, 'recipient_id' => $otherRecipient->id, 'body' => 'not for this thread']);

    $component = Livewire::actingAs($this->sender)
        ->test(DirectMessages::class)
        ->call('selectConversation', $this->recipient->id)
        ->call('catchUp', $first->id);

    $catchUpIds = collect($component->get('catchUpMessages'))->pluck('id')->all();

    expect($catchUpIds)->toBe([$second->id, $third->id]);
});

it('sets lastSeenMessageId to the newest message in the open thread', function () {
    $message = DirectMessage::create(['sender_id' => $this->recipient->id, 'recipient_id' => $this->sender->id, 'body' => 'hi']);

    Livewire::actingAs($this->sender)
        ->test(DirectMessages::class)
        ->call('selectConversation', $this->recipient->id)
        ->assertSet('lastSeenMessageId', $message->id);
});

it('accepts a receiveMessage call from the client listener without error', function () {
    Livewire::actingAs($this->sender)
        ->test(DirectMessages::class)
        ->call('selectConversation', $this->recipient->id)
        ->call('receiveMessage', ['id' => 999, 'sender_id' => $this->recipient->id, 'sender_name' => 'X', 'body' => 'hi', 'created_at' => now()->toIso8601String()])
        ->assertOk();
});
