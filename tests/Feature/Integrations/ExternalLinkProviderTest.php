<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\Drivers\ExternalLinkProvider;
use App\Integrations\Drivers\FakeLiveClassDriver;
use App\Integrations\LiveClassProvider;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Live Q&A', 'type' => 'live_session', 'position' => 0]);

    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-batch',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);

    $this->session = BatchSession::create([
        'batch_id' => $batch->id,
        'lesson_id' => $lesson->id,
        'starts_at' => now()->addWeek(),
        'duration_minutes' => 60,
        'position' => 0,
        'live_provider' => 'external_link',
        'provider_meeting_ref' => 'https://meet.example.com/room-1',
    ]);

    $this->viewer = User::factory()->create(['tenant_id' => $tenant->id]);
    $this->driver = new ExternalLinkProvider;
});

it('resolves LiveClassProvider from the container as FakeLiveClassDriver', function () {
    expect(app(LiveClassProvider::class))->toBeInstanceOf(FakeLiveClassDriver::class);
});

it('passes the teacher-supplied meeting URL through unchanged as the room ref', function () {
    expect($this->driver->createRoom($this->session))->toBe('https://meet.example.com/room-1');
});

it('rejects creating a room when no meeting URL was supplied', function () {
    $this->session->update(['provider_meeting_ref' => null]);

    expect(fn () => $this->driver->createRoom($this->session))->toThrow(InvalidArgumentException::class);
});

it('resolves the same meeting URL as the join url for any viewer', function () {
    expect($this->driver->joinUrl($this->session, $this->viewer))->toBe('https://meet.example.com/room-1');
});

it('has nothing to tear down on markEnded', function () {
    $this->driver->markEnded($this->session);

    expect($this->session->fresh()->provider_meeting_ref)->toBe('https://meet.example.com/room-1');
});
