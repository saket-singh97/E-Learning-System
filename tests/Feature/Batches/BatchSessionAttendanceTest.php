<?php

use App\Domains\Batches\Events\AttendanceRecorded;
use App\Domains\Batches\Filament\Resources\Batches\BatchResource;
use App\Domains\Batches\Filament\Resources\Batches\Pages\EditBatch;
use App\Domains\Batches\Filament\Resources\Batches\RelationManagers\BatchSessionsRelationManager;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\AttendanceRecord;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin->assignRole('institute_admin');

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $this->liveLesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Live Q&A', 'type' => 'live_session', 'position' => 0]);

    $this->batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-batch',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);

    $this->session = BatchSession::create([
        'batch_id' => $this->batch->id,
        'lesson_id' => $this->liveLesson->id,
        'starts_at' => '2026-09-08 10:00:00',
        'duration_minutes' => 60,
        'position' => 0,
    ]);

    $this->plainSession = BatchSession::create([
        'batch_id' => $this->batch->id,
        'starts_at' => '2026-09-09 10:00:00',
        'duration_minutes' => 60,
        'position' => 1,
    ]);

    $this->enrol = function (string $status = 'active'): User {
        $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $student->assignRole('student');

        Enrolment::create([
            'batch_id' => $this->batch->id,
            'user_id' => $student->id,
            'status' => $status,
            'source' => 'manual_add',
            'requested_at' => now(),
        ]);

        return $student;
    };

    $this->actingAs($this->admin)
        ->get(BatchResource::getUrl('edit', ['record' => $this->batch, 'subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();
});

it('marks every enrolled student present when nobody is tapped off', function () {
    $alice = ($this->enrol)();
    $bob = ($this->enrol)();

    Livewire::actingAs($this->admin)
        ->test(BatchSessionsRelationManager::class, [
            'ownerRecord' => $this->batch,
            'pageClass' => EditBatch::class,
        ])
        ->callTableAction('markAttendance', $this->session, data: [
            'present_user_ids' => [$alice->id, $bob->id],
        ])
        ->assertHasNoTableActionErrors();

    expect(AttendanceRecord::where('attendable_type', BatchSession::class)->where('attendable_id', $this->session->id)->count())->toBe(2)
        ->and(AttendanceRecord::where('user_id', $alice->id)->first()->status)->toBe('present')
        ->and(AttendanceRecord::where('user_id', $bob->id)->first()->status)->toBe('present');
});

it('marks a tapped-off student absent while the rest stay present', function () {
    $alice = ($this->enrol)();
    $bob = ($this->enrol)();

    Livewire::actingAs($this->admin)
        ->test(BatchSessionsRelationManager::class, [
            'ownerRecord' => $this->batch,
            'pageClass' => EditBatch::class,
        ])
        ->callTableAction('markAttendance', $this->session, data: [
            'present_user_ids' => [$alice->id],
        ])
        ->assertHasNoTableActionErrors();

    expect(AttendanceRecord::where('user_id', $alice->id)->first()->status)->toBe('present')
        ->and(AttendanceRecord::where('user_id', $bob->id)->first()->status)->toBe('absent');
});

it('records attendable_type, source, and marked_by correctly', function () {
    $alice = ($this->enrol)();

    Livewire::actingAs($this->admin)
        ->test(BatchSessionsRelationManager::class, [
            'ownerRecord' => $this->batch,
            'pageClass' => EditBatch::class,
        ])
        ->callTableAction('markAttendance', $this->session, data: [
            'present_user_ids' => [$alice->id],
        ]);

    $record = AttendanceRecord::where('user_id', $alice->id)->first();

    expect($record->attendable_type)->toBe(BatchSession::class)
        ->and($record->attendable_id)->toBe($this->session->id)
        ->and($record->source)->toBe('manual')
        ->and($record->marked_by)->toBe($this->admin->id)
        ->and($record->recorded_at)->not->toBeNull();
});

it('re-running the action updates existing records instead of duplicating them', function () {
    $alice = ($this->enrol)();
    $bob = ($this->enrol)();

    $relationManager = Livewire::actingAs($this->admin)
        ->test(BatchSessionsRelationManager::class, [
            'ownerRecord' => $this->batch,
            'pageClass' => EditBatch::class,
        ]);

    $relationManager->callTableAction('markAttendance', $this->session, data: [
        'present_user_ids' => [$alice->id, $bob->id],
    ]);

    expect(AttendanceRecord::where('user_id', $bob->id)->first()->status)->toBe('present');

    $relationManager->callTableAction('markAttendance', $this->session, data: [
        'present_user_ids' => [$alice->id],
    ]);

    expect(AttendanceRecord::where('attendable_type', BatchSession::class)->where('attendable_id', $this->session->id)->count())->toBe(2)
        ->and(AttendanceRecord::where('user_id', $bob->id)->first()->status)->toBe('absent');
});

it('only writes attendance for actively enrolled students, not waitlisted or pending ones', function () {
    $active = ($this->enrol)('active');
    ($this->enrol)('waitlisted');
    ($this->enrol)('pending_approval');

    Livewire::actingAs($this->admin)
        ->test(BatchSessionsRelationManager::class, [
            'ownerRecord' => $this->batch,
            'pageClass' => EditBatch::class,
        ])
        ->callTableAction('markAttendance', $this->session, data: [
            'present_user_ids' => [$active->id],
        ]);

    expect(AttendanceRecord::where('attendable_type', BatchSession::class)->where('attendable_id', $this->session->id)->count())->toBe(1);
});

it('fires AttendanceRecorded with the right student, tenant, and status for every enrolled student', function () {
    Event::fake([AttendanceRecorded::class]);

    $alice = ($this->enrol)();
    $bob = ($this->enrol)();

    Livewire::actingAs($this->admin)
        ->test(BatchSessionsRelationManager::class, [
            'ownerRecord' => $this->batch,
            'pageClass' => EditBatch::class,
        ])
        ->callTableAction('markAttendance', $this->session, data: [
            'present_user_ids' => [$alice->id],
        ]);

    Event::assertDispatched(AttendanceRecorded::class, fn (AttendanceRecorded $event) => $event->userId === $alice->id
        && $event->tenantId === $this->tenant->id
        && $event->status === 'present');

    Event::assertDispatched(AttendanceRecorded::class, fn (AttendanceRecorded $event) => $event->userId === $bob->id
        && $event->tenantId === $this->tenant->id
        && $event->status === 'absent');
});

it('hides the action for a plain timetable entry with no linked lesson', function () {
    Livewire::actingAs($this->admin)
        ->test(BatchSessionsRelationManager::class, [
            'ownerRecord' => $this->batch,
            'pageClass' => EditBatch::class,
        ])
        ->assertTableActionHidden('markAttendance', $this->plainSession);
});
