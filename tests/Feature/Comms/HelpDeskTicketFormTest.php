<?php

use App\Domains\Comms\Livewire\HelpDeskTicketForm;
use App\Domains\Comms\Models\HelpDeskTicket;
use App\Domains\Comms\Models\HelpDeskTicketReply;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->requester = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

it('raises a ticket with sla_due_at computed from the configured hours for each priority', function () {
    foreach (['low' => 72, 'normal' => 48, 'high' => 24] as $priority => $hours) {
        $now = now();
        Livewire::actingAs($this->requester)
            ->test(HelpDeskTicketForm::class)
            ->set('subject', ucfirst($priority).' issue')
            ->set('body', 'Something is broken')
            ->set('priority', $priority)
            ->call('raiseTicket')
            ->assertHasNoErrors();

        $ticket = HelpDeskTicket::where('subject', ucfirst($priority).' issue')->firstOrFail();
        expect($ticket->priority)->toBe($priority);
        expect($ticket->status)->toBe('open');
        expect($ticket->sla_due_at->diffInHours($now, true))->toBeGreaterThanOrEqual($hours - 1);
        expect($ticket->sla_due_at->diffInHours($now, true))->toBeLessThanOrEqual($hours + 1);
    }
});

it('lets a requester view their own ticket\'s public replies but never an internal note', function () {
    $ticket = HelpDeskTicket::create(['requester_id' => $this->requester->id, 'subject' => 'Login issue', 'body' => 'Cannot log in', 'sla_due_at' => now()->addDay()]);
    $staff = User::factory()->create(['tenant_id' => $this->tenant->id]);

    HelpDeskTicketReply::create(['ticket_id' => $ticket->id, 'author_id' => $staff->id, 'body' => 'Please try resetting your password', 'is_internal_note' => false]);
    HelpDeskTicketReply::create(['ticket_id' => $ticket->id, 'author_id' => $staff->id, 'body' => 'Known issue, escalate to engineering', 'is_internal_note' => true]);

    Livewire::actingAs($this->requester)
        ->test(HelpDeskTicketForm::class)
        ->call('openTicket', $ticket->id)
        ->assertSee('Please try resetting your password')
        ->assertDontSee('escalate to engineering');
});

it('never lets a different user open or read someone else\'s ticket', function () {
    $ticket = HelpDeskTicket::create(['requester_id' => $this->requester->id, 'subject' => 'Private issue', 'body' => 'x', 'sla_due_at' => now()->addDay()]);
    HelpDeskTicketReply::create(['ticket_id' => $ticket->id, 'author_id' => $this->requester->id, 'body' => 'Secret reply content', 'is_internal_note' => false]);

    $otherUser = User::factory()->create(['tenant_id' => $this->tenant->id]);

    Livewire::actingAs($otherUser)
        ->test(HelpDeskTicketForm::class)
        ->call('openTicket', $ticket->id)
        ->assertDontSee('Secret reply content');

    // Direct property-set bypass attempt (openTicketId is a plain public
    // Livewire property) must still be refused by replies()'s own re-check.
    Livewire::actingAs($otherUser)
        ->test(HelpDeskTicketForm::class)
        ->set('openTicketId', $ticket->id)
        ->assertDontSee('Secret reply content');
});
