<?php

use App\Domains\Comms\Livewire\DirectMessages;
use App\Domains\Comms\Models\DirectMessage;
use App\Domains\Comms\Models\MessageReport;
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

    $this->message = DirectMessage::create(['sender_id' => $this->sender->id, 'recipient_id' => $this->recipient->id, 'body' => 'Nasty stuff']);
});

it('lets the recipient report the sender own message', function () {
    Livewire::actingAs($this->recipient)
        ->test(DirectMessages::class)
        ->call('selectConversation', $this->sender->id)
        ->set("reportReasons.{$this->message->id}", 'Abusive language')
        ->call('reportMessage', $this->message->id);

    $report = MessageReport::where('direct_message_id', $this->message->id)->sole();
    expect($report->reporter_id)->toBe($this->recipient->id)
        ->and($report->reason)->toBe('Abusive language')
        ->and($report->status)->toBe('open');
});

it('does not let a sender report their own message', function () {
    Livewire::actingAs($this->sender)
        ->test(DirectMessages::class)
        ->call('selectConversation', $this->recipient->id)
        ->set("reportReasons.{$this->message->id}", 'Abusive language')
        ->call('reportMessage', $this->message->id);

    expect(MessageReport::count())->toBe(0);
});

it('does not let a viewer report a message outside their own thread', function () {
    $outsider = User::factory()->create(['tenant_id' => $this->tenant->id]);

    Livewire::actingAs($outsider)
        ->test(DirectMessages::class)
        ->call('selectConversation', $this->sender->id)
        ->set("reportReasons.{$this->message->id}", 'Abusive language')
        ->call('reportMessage', $this->message->id);

    expect(MessageReport::count())->toBe(0);
});

it('hides an actioned-reported message from the thread but keeps an open-reported one visible', function () {
    MessageReport::create(['reporter_id' => $this->recipient->id, 'direct_message_id' => $this->message->id, 'reason' => 'x', 'status' => 'actioned']);

    Livewire::actingAs($this->recipient)
        ->test(DirectMessages::class)
        ->call('selectConversation', $this->sender->id)
        ->assertDontSee('Nasty stuff');
});
