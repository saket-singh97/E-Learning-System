<?php

namespace App\Livewire;

use App\Notifications\AbsenceAlertDue;
use App\Notifications\EnrolmentRequestExpired;
use App\Notifications\FeeReminderDue;
use App\Notifications\InactivityNudgeDue;
use App\Notifications\LowAttendanceAlertDue;
use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Minimal bell/list over the existing `notifications` table — mark read /
 * mark all read only, no preferences UI. FEATURES.md's own N3 row also
 * asks for "per-category preferences," but both the design and plan docs
 * deliberately narrow this block to a read view (design doc §2.4 decision
 * 8) — built to that narrowed spec, not the fuller FEATURES.md wording.
 *
 * One component, embedded independently everywhere a notified user might
 * be: the student/parent portal Blade layouts (`<livewire:notification-
 * centre />` directly) and all three Filament panels (a single
 * TOPBAR_END render hook registered once in AppServiceProvider::boot(),
 * not per-panel — an unscoped registerRenderHook() call applies to every
 * panel by default).
 */
class NotificationCentre extends Component
{
    public function markAsRead(string $notificationId): void
    {
        $this->ownNotification($notificationId)?->markAsRead();
    }

    public function markAllAsRead(): void
    {
        Auth::user()?->unreadNotifications->markAsRead();
    }

    /**
     * Scoped through the authenticated user's own notifications()
     * relation, never DatabaseNotification::find() directly — a tampered
     * id belonging to another user's notification simply matches nothing
     * here, so it can't be marked read through this component.
     */
    protected function ownNotification(string $id): ?DatabaseNotification
    {
        return Auth::user()?->notifications()->whereKey($id)->first();
    }

    public function summarize(DatabaseNotification $notification): string
    {
        return match ($notification->type) {
            AbsenceAlertDue::class => ($notification->data['student_name'] ?? 'A student').' was marked absent',
            FeeReminderDue::class => 'Fee reminder ('.str_replace('_', ' ', $notification->data['stage'] ?? 'due').')',
            EnrolmentRequestExpired::class => 'Enrolment request expired: '.($notification->data['batch_title'] ?? ''),
            LowAttendanceAlertDue::class => ($notification->data['student_name'] ?? 'A student').' is below the attendance/coverage threshold',
            InactivityNudgeDue::class => 'No learning activity in the last few days — come back and continue where you left off',
            default => class_basename($notification->type),
        };
    }

    public function render(): View
    {
        $user = Auth::user();

        return view('livewire.notification-centre', [
            'notifications' => $user?->notifications()->limit(10)->get() ?? collect(),
            'unreadCount' => $user?->unreadNotifications()->count() ?? 0,
        ]);
    }
}
