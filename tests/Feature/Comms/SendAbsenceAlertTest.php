<?php

use App\Domains\Batches\Events\AttendanceRecorded;
use App\Domains\Comms\Models\ParentGuardianLink;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Notifications\AbsenceAlertDue;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Riya']);
    $this->student->assignRole('student');

    $this->parent = User::factory()->create(['tenant_id' => $this->tenant->id, 'phone' => '+919876543210']);
    $this->parent->assignRole('parent');

    ParentGuardianLink::create([
        'parent_user_id' => $this->parent->id,
        'student_user_id' => $this->student->id,
        'relationship' => 'father',
    ]);
});

it('notifies every linked parent when a student is marked absent', function () {
    Notification::fake();

    event(new AttendanceRecorded($this->student->id, $this->tenant->id, 'absent'));

    Notification::assertSentTo(
        $this->parent,
        AbsenceAlertDue::class,
        fn (AbsenceAlertDue $notification) => $notification->student->is($this->student)
    );
});

it('does not notify anyone when the student is marked present', function () {
    Notification::fake();

    event(new AttendanceRecorded($this->student->id, $this->tenant->id, 'present'));

    Notification::assertNothingSent();
});

it('does not notify a parent linked to a different student', function () {
    Notification::fake();

    $otherStudent = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $otherStudent->assignRole('student');
    $otherParent = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $otherParent->assignRole('parent');
    ParentGuardianLink::create([
        'parent_user_id' => $otherParent->id,
        'student_user_id' => $otherStudent->id,
        'relationship' => 'mother',
    ]);

    event(new AttendanceRecorded($this->student->id, $this->tenant->id, 'absent'));

    Notification::assertSentTo($this->parent, AbsenceAlertDue::class);
    Notification::assertNotSentTo($otherParent, AbsenceAlertDue::class);
});

it('sends only one alert per student per day, even if attendance is re-marked absent again', function () {
    event(new AttendanceRecorded($this->student->id, $this->tenant->id, 'absent'));
    event(new AttendanceRecorded($this->student->id, $this->tenant->id, 'absent'));

    expect($this->parent->notifications()->where('type', AbsenceAlertDue::class)->count())->toBe(1);
});

it('does not let one linked students same-day alert suppress a second linked students own alert', function () {
    // The design doc's own dedupe wording ("same notifiable, same type,
    // same day") would silently drop this second alert if taken literally
    // — SendAbsenceAlert additionally keys on which student the alert is
    // about, read back from the stored notification's data payload.
    $secondStudent = User::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Aarav']);
    $secondStudent->assignRole('student');
    ParentGuardianLink::create([
        'parent_user_id' => $this->parent->id,
        'student_user_id' => $secondStudent->id,
        'relationship' => 'father',
    ]);

    event(new AttendanceRecorded($this->student->id, $this->tenant->id, 'absent'));
    event(new AttendanceRecorded($secondStudent->id, $this->tenant->id, 'absent'));

    expect($this->parent->notifications()->where('type', AbsenceAlertDue::class)->count())->toBe(2);
});

it('does nothing when the event references a user that no longer exists', function () {
    Notification::fake();

    event(new AttendanceRecorded(999999, $this->tenant->id, 'absent'));

    Notification::assertNothingSent();
});
