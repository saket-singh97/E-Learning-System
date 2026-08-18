<?php

use App\Domains\Batches\Events\LiveSessionRoomCreated;
use App\Domains\Batches\Filament\Resources\Batches\BatchResource;
use App\Domains\Batches\Filament\Resources\Batches\Pages\EditBatch;
use App\Domains\Batches\Filament\Resources\Batches\RelationManagers\BatchSessionsRelationManager;
use App\Domains\Batches\Models\Batch;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\Drivers\CircuitBreakerGuardedLiveClassDriver;
use App\Integrations\Drivers\FakeLiveClassDriver;
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
        'status' => 'draft',
    ]);

    $this->actingAs($this->admin)
        ->get(BatchResource::getUrl('edit', ['record' => $this->batch, 'subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();
});

it('creates a room and fires LiveSessionRoomCreated when a session links a live_session lesson', function () {
    Event::fake([LiveSessionRoomCreated::class]);

    Livewire::test(BatchSessionsRelationManager::class, [
        'ownerRecord' => $this->batch,
        'pageClass' => EditBatch::class,
    ])->callTableAction('create', data: [
        'title' => 'Live Q&A session',
        'starts_at' => '2026-09-08 10:00:00',
        'duration_minutes' => 60,
        'lesson_id' => $this->liveLesson->id,
        'provider_meeting_ref' => 'https://meet.example.com/room-1',
    ])->assertHasNoTableActionErrors();

    $session = $this->batch->fresh()->sessions()->first();

    expect($session->lesson_id)->toBe($this->liveLesson->id)
        ->and($session->live_provider)->toBe('external_link')
        ->and($session->provider_meeting_ref)->toBe('https://meet.example.com/room-1')
        ->and($session->room_created_at)->not->toBeNull();

    Event::assertDispatched(LiveSessionRoomCreated::class, fn ($event) => $event->batchSessionId === $session->id
        && $event->tenantId === $this->tenant->id);
});

it('creates a room via the Fake driver and requires no meeting url when zoom_video_sdk is chosen', function () {
    Event::fake([LiveSessionRoomCreated::class]);

    Livewire::test(BatchSessionsRelationManager::class, [
        'ownerRecord' => $this->batch,
        'pageClass' => EditBatch::class,
    ])->callTableAction('create', data: [
        'title' => 'Live Q&A session',
        'starts_at' => '2026-09-08 10:00:00',
        'duration_minutes' => 60,
        'lesson_id' => $this->liveLesson->id,
        'live_provider' => 'zoom_video_sdk',
    ])->assertHasNoTableActionErrors();

    $session = $this->batch->fresh()->sessions()->first();

    expect($session->live_provider)->toBe('zoom_video_sdk')
        ->and($session->room_created_at)->not->toBeNull()
        // zoom_video_sdk resolves through the container's LiveClassProvider
        // binding (FakeLiveClassDriver, no real Zoom credentials exist yet),
        // not ZoomVideoSdkProvider directly — so this is Fake's hash-seeded
        // room ref, not a Zoom session name.
        ->and($session->provider_meeting_ref)->toBe(app(FakeLiveClassDriver::class)->createRoom($session));

    Event::assertDispatched(LiveSessionRoomCreated::class);
});

it('resolveLiveClassProvider() routes zoom_video_sdk through the LiveClassProvider container binding', function () {
    $session = $this->batch->sessions()->create([
        'lesson_id' => $this->liveLesson->id,
        'starts_at' => '2026-09-08 10:00:00',
        'duration_minutes' => 60,
        'position' => 0,
        'live_provider' => 'zoom_video_sdk',
    ]);

    // S12 B11 — the binding now resolves the circuit-breaker decorator
    // wrapping FakeLiveClassDriver, not the bare fake directly.
    expect($session->resolveLiveClassProvider())->toBeInstanceOf(CircuitBreakerGuardedLiveClassDriver::class);
});

it('leaves a plain timetable entry with no lesson link unrouted and fires no room event', function () {
    Event::fake([LiveSessionRoomCreated::class]);

    Livewire::test(BatchSessionsRelationManager::class, [
        'ownerRecord' => $this->batch,
        'pageClass' => EditBatch::class,
    ])->callTableAction('create', data: [
        'title' => 'Plain session',
        'starts_at' => '2026-09-08 10:00:00',
        'duration_minutes' => 60,
    ])->assertHasNoTableActionErrors();

    $session = $this->batch->fresh()->sessions()->first();

    expect($session->lesson_id)->toBeNull()
        ->and($session->live_provider)->toBeNull()
        ->and($session->provider_meeting_ref)->toBeNull()
        ->and($session->room_created_at)->toBeNull();

    Event::assertNotDispatched(LiveSessionRoomCreated::class);
});

it('requires a meeting URL once a lesson is linked', function () {
    Livewire::test(BatchSessionsRelationManager::class, [
        'ownerRecord' => $this->batch,
        'pageClass' => EditBatch::class,
    ])->callTableAction('create', data: [
        'title' => 'Missing URL',
        'starts_at' => '2026-09-08 10:00:00',
        'duration_minutes' => 60,
        'lesson_id' => $this->liveLesson->id,
    ])->assertHasTableActionErrors(['provider_meeting_ref']);

    expect($this->batch->fresh()->sessions()->count())->toBe(0);
});

it('does not re-create the room or re-fire the event when an already-roomed session is edited', function () {
    Event::fake([LiveSessionRoomCreated::class]);

    $relationManager = Livewire::test(BatchSessionsRelationManager::class, [
        'ownerRecord' => $this->batch,
        'pageClass' => EditBatch::class,
    ]);

    $relationManager->callTableAction('create', data: [
        'title' => 'Live Q&A session',
        'starts_at' => '2026-09-08 10:00:00',
        'duration_minutes' => 60,
        'lesson_id' => $this->liveLesson->id,
        'provider_meeting_ref' => 'https://meet.example.com/room-1',
    ])->assertHasNoTableActionErrors();

    $session = $this->batch->fresh()->sessions()->first();
    $firstRoomCreatedAt = $session->room_created_at;

    $relationManager->callTableAction('edit', $session, data: [
        'title' => 'Live Q&A session (renamed)',
        'starts_at' => '2026-09-08 10:00:00',
        'duration_minutes' => 60,
        'lesson_id' => $this->liveLesson->id,
        'provider_meeting_ref' => 'https://meet.example.com/room-1',
    ])->assertHasNoTableActionErrors();

    expect($session->fresh()->title)->toBe('Live Q&A session (renamed)')
        ->and($session->fresh()->room_created_at->equalTo($firstRoomCreatedAt))->toBeTrue();

    Event::assertDispatchedTimes(LiveSessionRoomCreated::class, 1);
});
