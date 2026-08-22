<?php

use App\Domains\Batches\Filament\Resources\Batches\BatchResource;
use App\Domains\Batches\Filament\Resources\Batches\Pages\EditBatch;
use App\Domains\Batches\Filament\Resources\Batches\RelationManagers\BatchSessionsRelationManager;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\TimetableTemplate;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin->assignRole('institute_admin');

    $this->instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->instructor->assignRole('instructor');

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $this->section = CourseSection::create(['course_id' => $course->id, 'title' => 'Mechanics', 'position' => 0]);
    $this->lesson = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'Live class', 'type' => 'live_session', 'position' => 0]);

    $this->batchA = Batch::create([
        'course_id' => $course->id,
        'instructor_id' => $this->instructor->id,
        'title' => 'Batch A',
        'slug' => 'batch-a',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    $this->batchB = Batch::create([
        'course_id' => $course->id,
        'instructor_id' => $this->instructor->id,
        'title' => 'Batch B',
        'slug' => 'batch-b',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);

    $this->existingSession = BatchSession::create([
        'batch_id' => $this->batchA->id,
        'lesson_id' => $this->lesson->id,
        'starts_at' => '2026-09-01 09:00:00',
        'duration_minutes' => 60,
        'position' => 0,
        'live_provider' => 'external_link',
        'provider_meeting_ref' => 'https://example.test/meet',
    ]);

    $this->actingAs($this->admin)
        ->get(BatchResource::getUrl('edit', ['record' => $this->batchB, 'subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();
});

it('refuses creating a session that overlaps another batch already scheduled for this instructor', function () {
    Livewire::test(BatchSessionsRelationManager::class, [
        'ownerRecord' => $this->batchB,
        'pageClass' => EditBatch::class,
    ])->callTableAction('create', data: [
        'starts_at' => '2026-09-01 09:30:00',
        'duration_minutes' => 30,
    ])->assertHasTableActionErrors(['starts_at']);

    expect(BatchSession::where('batch_id', $this->batchB->id)->count())->toBe(0);
});

it('allows creating a non-overlapping session', function () {
    Livewire::test(BatchSessionsRelationManager::class, [
        'ownerRecord' => $this->batchB,
        'pageClass' => EditBatch::class,
    ])->callTableAction('create', data: [
        'starts_at' => '2026-09-01 10:00:00',
        'duration_minutes' => 60,
    ])->assertHasNoTableActionErrors();

    expect(BatchSession::where('batch_id', $this->batchB->id)->count())->toBe(1);
});

it('allows editing a session back into its own unchanged slot', function () {
    Livewire::test(BatchSessionsRelationManager::class, [
        'ownerRecord' => $this->batchA,
        'pageClass' => EditBatch::class,
    ])->callTableAction('edit', $this->existingSession, data: [
        'starts_at' => '2026-09-01 09:00:00',
        'duration_minutes' => 60,
    ])->assertHasNoTableActionErrors();
});

it('prefills starts_at and duration_minutes from a selected timetable template', function () {
    $template = TimetableTemplate::create([
        'title' => 'Morning Slot',
        'day_of_week' => 2,
        'starts_at' => '09:30:00',
        'duration_minutes' => 45,
    ]);

    Livewire::test(BatchSessionsRelationManager::class, [
        'ownerRecord' => $this->batchB,
        'pageClass' => EditBatch::class,
    ])->mountTableAction('create')
        ->setTableActionData(['from_template' => $template->id])
        ->assertTableActionDataSet(fn (array $data): bool => $data['duration_minutes'] === 45
            && str_contains((string) $data['starts_at'], '09:30:00'));
});
