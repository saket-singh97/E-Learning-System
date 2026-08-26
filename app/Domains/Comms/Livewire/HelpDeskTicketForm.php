<?php

namespace App\Domains\Comms\Livewire;

use App\Domains\Comms\Models\HelpDeskTicket;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * S19 N16 pt.1 — student/staff-facing raise-a-ticket flow. Read-only on the
 * thread side (a requester can view their own ticket's replies, minus any
 * `is_internal_note` one); replying is B8's staff-queue concern.
 */
class HelpDeskTicketForm extends Component
{
    public ?int $openTicketId = null;

    public string $subject = '';

    public string $body = '';

    public string $priority = 'normal';

    public function myTickets(): Collection
    {
        $viewer = auth()->user();

        if (! $viewer) {
            return collect();
        }

        return HelpDeskTicket::where('requester_id', $viewer->id)
            ->orderByDesc('created_at')
            ->get();
    }

    public function openTicket(int $ticketId): void
    {
        $ticket = $this->ownTicket($ticketId);

        if ($ticket !== null) {
            $this->openTicketId = $ticket->id;
        }
    }

    public function closeTicket(): void
    {
        $this->openTicketId = null;
    }

    /**
     * Called by the client's Echo listener on `help-desk-ticket.{id}`
     * (S19 B9) when a `reply.created` broadcast arrives. The payload
     * carries nothing this method needs to read — any Livewire round trip
     * re-renders `replies()` fresh from the database.
     */
    public function receiveReply(array $payload): void
    {
        //
    }

    /**
     * `is_internal_note` replies are staff-only — never returned here.
     * Re-verifies ownership on every call, not just at `openTicket()` time:
     * `openTicketId` is a plain public Livewire property, settable
     * directly from the client, same discipline `SurveyRunner::questions()`
     * (S19 B5) already established for its own client-settable id.
     */
    public function replies(): Collection
    {
        if ($this->openTicketId === null) {
            return collect();
        }

        $ticket = $this->ownTicket($this->openTicketId);

        if ($ticket === null) {
            return collect();
        }

        return $ticket->replies()->where('is_internal_note', false)->get();
    }

    protected function ownTicket(int $ticketId): ?HelpDeskTicket
    {
        $viewer = auth()->user();

        if (! $viewer) {
            return null;
        }

        return HelpDeskTicket::where('requester_id', $viewer->id)->find($ticketId);
    }

    public function raiseTicket(): void
    {
        $viewer = auth()->user();

        if (! $viewer) {
            return;
        }

        $this->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string|max:5000',
            'priority' => 'required|in:'.implode(',', HelpDeskTicket::PRIORITIES),
        ]);

        $slaHours = config('comms.help_desk_sla_hours')[$this->priority];

        $ticket = HelpDeskTicket::create([
            'requester_id' => $viewer->id,
            'subject' => $this->subject,
            'body' => $this->body,
            'priority' => $this->priority,
            'sla_due_at' => now()->addHours($slaHours),
        ]);

        $this->subject = '';
        $this->body = '';
        $this->priority = 'normal';
        $this->openTicketId = $ticket->id;
    }

    public function render(): View
    {
        return view('comms::help-desk-ticket-form', [
            'tickets' => $this->myTickets(),
            'replies' => $this->replies(),
            'canRespond' => auth()->user() !== null,
        ]);
    }
}
