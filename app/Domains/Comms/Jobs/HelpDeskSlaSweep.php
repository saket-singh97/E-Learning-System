<?php

namespace App\Domains\Comms\Jobs;

use App\Domains\Comms\Models\HelpDeskTicket;
use App\Models\User;
use App\Notifications\HelpDeskSlaBreached;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * S19 N16 pt.3 — same unscoped-daily-sweep shape `TeacherAttendanceSweep`
 * (S16) already established: `CurrentTenant` explicitly cleared up front,
 * `BelongsToTenant`'s global scope a genuine no-op across every tenant's
 * rows. Notifies the assigned staff member, or every institute_admin for
 * the tenant if unassigned (design doc §4 decision 7) — same "no dedicated
 * recipient concept, institute_admin is the closest real fit" reasoning
 * `TeacherAttendanceFlagged`/`GracePeriodReminder` already established.
 */
class HelpDeskSlaSweep implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        app(CurrentTenant::class)->clear();

        $this->breachedTickets()->each(function (HelpDeskTicket $ticket): void {
            $recipients = $ticket->assigned_to_user_id !== null
                ? User::where('id', $ticket->assigned_to_user_id)->get()
                : User::where('tenant_id', $ticket->tenant_id)->role('institute_admin')->get();

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new HelpDeskSlaBreached($ticket));
            }

            $ticket->update(['sla_breach_notified_at' => now()]);
        });
    }

    /**
     * Still `open`/`in_progress` past its own `sla_due_at`, not already
     * notified — `sla_breach_notified_at` is the dedup marker so a
     * permanently-breached open ticket isn't re-notified every single day
     * forever, same "flag once, never re-flag" reasoning
     * `TeacherAttendanceFlag`'s own per-session dedup already established.
     *
     * @return Collection<int, HelpDeskTicket>
     */
    public function breachedTickets(): Collection
    {
        return HelpDeskTicket::where('sla_due_at', '<', now())
            ->whereIn('status', ['open', 'in_progress'])
            ->whereNull('sla_breach_notified_at')
            ->get();
    }
}
