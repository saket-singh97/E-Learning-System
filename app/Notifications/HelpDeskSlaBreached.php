<?php

namespace App\Notifications;

use App\Domains\Comms\Models\HelpDeskTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * S19 N16 pt.3 — database-only, same posture `TeacherAttendanceFlagged`
 * already established: an internal staff worklist item, not a
 * customer-facing alert.
 */
class HelpDeskSlaBreached extends Notification
{
    use Queueable;

    public function __construct(public readonly HelpDeskTicket $ticket) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'help_desk_ticket_id' => $this->ticket->id,
            'subject' => $this->ticket->subject,
        ];
    }
}
