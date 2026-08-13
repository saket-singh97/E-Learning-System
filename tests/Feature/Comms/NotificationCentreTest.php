<?php

use App\Domains\Tenancy\Models\Tenant;
use App\Livewire\NotificationCentre;
use App\Models\User;
use App\Notifications\AbsenceAlertDue;
use App\Notifications\EnrolmentRequestExpired;
use App\Notifications\FeeReminderDue;
use App\Notifications\InactivityNudgeDue;
use App\Notifications\LowAttendanceAlertDue;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
});

function makeNotificationRow(User $user, string $type, array $data, bool $read = false): DatabaseNotification
{
    return DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => $type,
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => $data,
        'read_at' => $read ? now() : null,
    ]);
}

it('shows the unread count and a human summary for each known notification type', function () {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    makeNotificationRow($user, AbsenceAlertDue::class, ['student_name' => 'Riya', 'student_user_id' => 1, 'date' => now()->toDateString()]);
    makeNotificationRow($user, FeeReminderDue::class, ['fee_invoice_id' => 1, 'stage' => 'due_today', 'due_date' => now()->toDateString()], read: true);
    makeNotificationRow($user, EnrolmentRequestExpired::class, ['enrolment_id' => 1, 'batch_id' => 1, 'batch_title' => 'Morning Batch']);
    makeNotificationRow($user, LowAttendanceAlertDue::class, ['student_user_id' => 1, 'student_name' => 'Aarav', 'attendance_percent' => 40.0, 'coverage_percent' => null]);
    makeNotificationRow($user, InactivityNudgeDue::class, ['last_active_at' => now()->subDays(5)->toDateString()]);

    Livewire::actingAs($user)
        ->test(NotificationCentre::class)
        ->assertSee('4 unread')
        ->assertSee('Riya was marked absent')
        ->assertSee('Fee reminder (due today)')
        ->assertSee('Enrolment request expired: Morning Batch')
        ->assertSee('Aarav is below the attendance/coverage threshold')
        ->assertSee('come back and continue where you left off');
});

it('falls back to the class basename for an unrecognised notification type', function () {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    makeNotificationRow($user, 'App\\Notifications\\SomeFutureNotification', ['foo' => 'bar']);

    Livewire::actingAs($user)
        ->test(NotificationCentre::class)
        ->assertSee('SomeFutureNotification');
});

it('renders nothing for a guest, without erroring', function () {
    Livewire::test(NotificationCentre::class)
        ->assertOk()
        ->assertDontSee('unread');
});

it('marks its own notification read', function () {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $notification = makeNotificationRow($user, EnrolmentRequestExpired::class, ['enrolment_id' => 1, 'batch_id' => 1, 'batch_title' => 'Morning Batch']);

    Livewire::actingAs($user)
        ->test(NotificationCentre::class)
        ->call('markAsRead', $notification->id);

    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('cannot mark another users notification read', function () {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $otherUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $othersNotification = makeNotificationRow($otherUser, EnrolmentRequestExpired::class, ['enrolment_id' => 1, 'batch_id' => 1, 'batch_title' => 'Morning Batch']);

    Livewire::actingAs($user)
        ->test(NotificationCentre::class)
        ->call('markAsRead', $othersNotification->id);

    expect($othersNotification->fresh()->read_at)->toBeNull();
});

it('marks all of the current users own unread notifications read, leaving other users notifications alone', function () {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $n1 = makeNotificationRow($user, EnrolmentRequestExpired::class, ['enrolment_id' => 1, 'batch_id' => 1, 'batch_title' => 'A']);
    $n2 = makeNotificationRow($user, EnrolmentRequestExpired::class, ['enrolment_id' => 2, 'batch_id' => 2, 'batch_title' => 'B']);

    $otherUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $othersNotification = makeNotificationRow($otherUser, EnrolmentRequestExpired::class, ['enrolment_id' => 3, 'batch_id' => 3, 'batch_title' => 'C']);

    Livewire::actingAs($user)
        ->test(NotificationCentre::class)
        ->call('markAllAsRead');

    expect($n1->fresh()->read_at)->not->toBeNull()
        ->and($n2->fresh()->read_at)->not->toBeNull()
        ->and($othersNotification->fresh()->read_at)->toBeNull();
});
