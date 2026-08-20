<?php

use App\Domains\Comms\Livewire\DirectMessages;
use App\Domains\Comms\Models\BlockedUser;
use App\Domains\Comms\Models\DirectMessage;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->sender = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->recipient = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

it('sends a message between two tenant users', function () {
    Livewire::actingAs($this->sender)
        ->test(DirectMessages::class)
        ->call('selectConversation', $this->recipient->id)
        ->set('newMessageBody', 'Hello there')
        ->call('send')
        ->assertSee('Hello there');

    expect(DirectMessage::where('sender_id', $this->sender->id)->where('recipient_id', $this->recipient->id)->exists())->toBeTrue();
});

it('shows both directions of a thread', function () {
    DirectMessage::create(['sender_id' => $this->sender->id, 'recipient_id' => $this->recipient->id, 'body' => 'Hi']);
    DirectMessage::create(['sender_id' => $this->recipient->id, 'recipient_id' => $this->sender->id, 'body' => 'Hello back']);

    Livewire::actingAs($this->sender)
        ->test(DirectMessages::class)
        ->call('selectConversation', $this->recipient->id)
        ->assertSee('Hi')
        ->assertSee('Hello back');
});

it('marks unread messages as read when the thread is selected', function () {
    $message = DirectMessage::create(['sender_id' => $this->recipient->id, 'recipient_id' => $this->sender->id, 'body' => 'Hi']);
    expect($message->read_at)->toBeNull();

    Livewire::actingAs($this->sender)
        ->test(DirectMessages::class)
        ->call('selectConversation', $this->recipient->id);

    expect($message->fresh()->read_at)->not->toBeNull();
});

it('no-ops sending for a guest', function () {
    Livewire::test(DirectMessages::class)
        ->set('recipientId', $this->recipient->id)
        ->set('newMessageBody', 'Hello')
        ->call('send');

    expect(DirectMessage::count())->toBe(0);
});

it('silences sending in either direction once one party blocks the other', function () {
    BlockedUser::create(['user_id' => $this->recipient->id, 'blocked_user_id' => $this->sender->id]);

    Livewire::actingAs($this->sender)
        ->test(DirectMessages::class)
        ->call('selectConversation', $this->recipient->id)
        ->set('newMessageBody', 'Hello')
        ->call('send');

    expect(DirectMessage::count())->toBe(0);
});

it('toggles a block on and off', function () {
    Livewire::actingAs($this->sender)
        ->test(DirectMessages::class)
        ->call('toggleBlock', $this->recipient->id);

    expect(BlockedUser::where('user_id', $this->sender->id)->where('blocked_user_id', $this->recipient->id)->exists())->toBeTrue();

    Livewire::actingAs($this->sender)
        ->test(DirectMessages::class)
        ->call('toggleBlock', $this->recipient->id);

    expect(BlockedUser::where('user_id', $this->sender->id)->where('blocked_user_id', $this->recipient->id)->exists())->toBeFalse();
});

it('refuses to send to a user outside the tenant', function () {
    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    app(CurrentTenant::class)->set($otherTenant);
    $outsider = User::factory()->create(['tenant_id' => $otherTenant->id]);
    app(CurrentTenant::class)->set($this->tenant);

    Livewire::actingAs($this->sender)
        ->test(DirectMessages::class)
        ->call('selectConversation', $outsider->id)
        ->set('newMessageBody', 'Hello')
        ->call('send');

    expect(DirectMessage::count())->toBe(0);
});

it('lists only real conversation partners', function () {
    $stranger = User::factory()->create(['tenant_id' => $this->tenant->id]);
    DirectMessage::create(['sender_id' => $this->sender->id, 'recipient_id' => $this->recipient->id, 'body' => 'Hi']);

    Livewire::actingAs($this->sender)
        ->test(DirectMessages::class)
        ->assertSee($this->recipient->name)
        ->assertDontSee('No conversations yet.');
});
