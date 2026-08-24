<?php

use App\Domains\Batches\Events\AttendanceRecorded;
use App\Domains\Batches\Filament\Resources\Batches\BatchResource;
use App\Domains\Batches\Filament\Resources\Batches\Pages\EditBatch;
use App\Domains\Batches\Filament\Resources\Batches\RelationManagers\BatchSessionsRelationManager;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
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

    $this->instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->instructor->assignRole('instructor');

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Live Q&A', 'type' => 'live_session', 'position' => 0]);

    $this->batch = Batch::create([
        'course_id' => $course->id,
        'instructor_id' => $this->instructor->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-batch',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);

    $this->session = BatchSession::create([
        'batch_id' => $this->batch->id,
        'lesson_id' => $lesson->id,
        'starts_at' => '2026-09-08 10:00:00',
        'duration_minutes' => 60,
        'position' => 0,
    ]);
});

it('lets an instructor mark their own attendance on the Teach panel', function () {
    Event::fake([AttendanceRecorded::class]);

    $this->actingAs($this->instructor)
        ->get(BatchResource::getUrl('edit', ['record' => $this->batch, 'subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();

    Livewire::actingAs($this->instructor)
        ->test(BatchSessionsRelationManager::class, [
            'ownerRecord' => $this->batch,
            'pageClass' => EditBatch::class,
        ])
        ->callTableAction('markMyAttendance', $this->session)
        ->assertHasNoTableActionErrors();

    $record = AttendanceRecord::where('attendable_type', BatchSession::class)
        ->where('attendable_id', $this->session->id)
        ->where('user_id', $this->instructor->id)
        ->sole();

    expect($record->role)->toBe('instructor');
    expect($record->status)->toBe('present');
    expect($record->source)->toBe('manual');

    Event::assertDispatched(AttendanceRecorded::class, fn (AttendanceRecorded $event) => $event->userId === $this->instructor->id
        && $event->tenantId === $this->tenant->id
        && $event->status === 'present');
});

it('is idempotent — running it twice updates the same row, not a second one', function () {
    $this->actingAs($this->instructor)
        ->get(BatchResource::getUrl('edit', ['record' => $this->batch, 'subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();

    $relationManager = Livewire::actingAs($this->instructor)
        ->test(BatchSessionsRelationManager::class, [
            'ownerRecord' => $this->batch,
            'pageClass' => EditBatch::class,
        ]);

    $relationManager->callTableAction('markMyAttendance', $this->session);
    $relationManager->callTableAction('markMyAttendance', $this->session);

    expect(AttendanceRecord::where('attendable_id', $this->session->id)->where('user_id', $this->instructor->id)->count())->toBe(1);
});

it('hides the action on the Admin panel', function () {
    $admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $admin->assignRole('institute_admin');

    $this->actingAs($admin)
        ->get(BatchResource::getUrl('edit', ['record' => $this->batch, 'subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    Livewire::actingAs($admin)
        ->test(BatchSessionsRelationManager::class, [
            'ownerRecord' => $this->batch,
            'pageClass' => EditBatch::class,
        ])
        ->assertTableActionHidden('markMyAttendance', $this->session);
});
