<?php

use App\Domains\Batches\Events\AttendanceRecorded;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Comms\Models\ParentGuardianLink;
use App\Domains\Content\Models\AttendanceRecord;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Notifications\LowAttendanceAlertDue;
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

function lowAttendanceSession(Tenant $tenant): BatchSession
{
    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);
    $liveLesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Live', 'type' => 'live_session', 'position' => 0]);

    return BatchSession::create(['batch_id' => $batch->id, 'lesson_id' => $liveLesson->id, 'starts_at' => now(), 'duration_minutes' => 60, 'position' => 0]);
}

it('notifies every linked parent once a students attendance crosses the 60% at-risk threshold', function () {
    Notification::fake();

    $session = lowAttendanceSession($this->tenant);
    AttendanceRecord::create(['attendable_type' => BatchSession::class, 'attendable_id' => $session->id, 'user_id' => $this->student->id, 'status' => 'absent', 'source' => 'manual', 'recorded_at' => now()]);

    event(new AttendanceRecorded($this->student->id, $this->tenant->id, 'absent'));

    Notification::assertSentTo(
        $this->parent,
        LowAttendanceAlertDue::class,
        fn (LowAttendanceAlertDue $notification) => $notification->student->is($this->student)
            && $notification->attendancePercent === 0.0
    );
});

it('still notifies correctly when the triggering event fires with CurrentTenant unbound', function () {
    // S9 B14 cross-block regression: RecordVideoCoverageAttendance and
    // AutoSubmitExpiredQuizAttempts-adjacent sweeps (S9 B10) now run as
    // real queued jobs that explicitly clear() CurrentTenant for their
    // own cross-tenant sweep, and RecordVideoCoverageAttendance fires
    // this exact AttendanceRecorded event from inside that cleared
    // context. Every query this listener's own call chain touches
    // (ParentGuardianLink, AttendanceRecord, FeeInvoice, QuizAttempt, all
    // via StudentMetrics) filters by a globally-unique user_id, so an
    // unbound CurrentTenant's global-scope no-op should never change the
    // result — verified here directly rather than only reasoned about.
    Notification::fake();

    // Fixture creation needs CurrentTenant bound (BelongsToTenant's
    // creating() hook throws otherwise) — cleared only afterward, right
    // before firing the event, to isolate what's actually under test.
    $session = lowAttendanceSession($this->tenant);
    AttendanceRecord::create(['attendable_type' => BatchSession::class, 'attendable_id' => $session->id, 'user_id' => $this->student->id, 'status' => 'absent', 'source' => 'manual', 'recorded_at' => now()]);

    app(CurrentTenant::class)->clear();

    event(new AttendanceRecorded($this->student->id, $this->tenant->id, 'absent'));

    Notification::assertSentTo(
        $this->parent,
        LowAttendanceAlertDue::class,
        fn (LowAttendanceAlertDue $notification) => $notification->student->is($this->student)
    );
});

it('does not notify when the student is still above the threshold', function () {
    Notification::fake();

    $session = lowAttendanceSession($this->tenant);
    AttendanceRecord::create(['attendable_type' => BatchSession::class, 'attendable_id' => $session->id, 'user_id' => $this->student->id, 'status' => 'present', 'source' => 'manual', 'recorded_at' => now()]);

    event(new AttendanceRecorded($this->student->id, $this->tenant->id, 'present'));

    Notification::assertNothingSent();
});

it('does not notify a student with no attendance/coverage data yet', function () {
    Notification::fake();

    // SendAbsenceAlert (S8 B3) is also registered on this same event and
    // fires unconditionally on 'absent' status — assertNotSentTo(), not
    // assertNothingSent(), since AbsenceAlertDue legitimately does send
    // here; only LowAttendanceAlertDue must not, since isAtRisk() treats
    // "no data yet" as absence of evidence, not evidence of struggling.
    event(new AttendanceRecorded($this->student->id, $this->tenant->id, 'absent'));

    Notification::assertNotSentTo($this->parent, LowAttendanceAlertDue::class);
});

it('sends only one alert per student per week, even if attendance is re-marked absent again', function () {
    $session = lowAttendanceSession($this->tenant);
    AttendanceRecord::create(['attendable_type' => BatchSession::class, 'attendable_id' => $session->id, 'user_id' => $this->student->id, 'status' => 'absent', 'source' => 'manual', 'recorded_at' => now()]);

    event(new AttendanceRecorded($this->student->id, $this->tenant->id, 'absent'));
    event(new AttendanceRecorded($this->student->id, $this->tenant->id, 'absent'));

    expect($this->parent->notifications()->where('type', LowAttendanceAlertDue::class)->count())->toBe(1);
});

it('does not let one linked students alert suppress a second linked students own alert', function () {
    $secondStudent = User::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Aarav']);
    $secondStudent->assignRole('student');
    ParentGuardianLink::create([
        'parent_user_id' => $this->parent->id,
        'student_user_id' => $secondStudent->id,
        'relationship' => 'father',
    ]);

    $session = lowAttendanceSession($this->tenant);
    AttendanceRecord::create(['attendable_type' => BatchSession::class, 'attendable_id' => $session->id, 'user_id' => $this->student->id, 'status' => 'absent', 'source' => 'manual', 'recorded_at' => now()]);
    AttendanceRecord::create(['attendable_type' => BatchSession::class, 'attendable_id' => $session->id, 'user_id' => $secondStudent->id, 'status' => 'absent', 'source' => 'manual', 'recorded_at' => now()]);

    event(new AttendanceRecorded($this->student->id, $this->tenant->id, 'absent'));
    event(new AttendanceRecorded($secondStudent->id, $this->tenant->id, 'absent'));

    expect($this->parent->notifications()->where('type', LowAttendanceAlertDue::class)->count())->toBe(2);
});

it('does nothing when the event references a user that no longer exists', function () {
    Notification::fake();

    event(new AttendanceRecorded(999999, $this->tenant->id, 'absent'));

    Notification::assertNothingSent();
});

it('renders a WhatsApp alert naming the student and their current numbers', function () {
    $session = lowAttendanceSession($this->tenant);
    AttendanceRecord::create(['attendable_type' => BatchSession::class, 'attendable_id' => $session->id, 'user_id' => $this->student->id, 'status' => 'absent', 'source' => 'manual', 'recorded_at' => now()]);

    event(new AttendanceRecorded($this->student->id, $this->tenant->id, 'absent'));

    $notification = $this->parent->notifications()->where('type', LowAttendanceAlertDue::class)->sole();

    // 0.0 round-trips through the notifications table's JSON `data` column
    // as a bare 0 (JSON doesn't distinguish int/float when there's no
    // fractional part) — decodes back as a PHP int, not a float.
    expect($notification->data['student_name'])->toBe('Riya')
        ->and((float) $notification->data['attendance_percent'])->toBe(0.0);
});
