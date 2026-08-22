<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\LivePoll;
use App\Domains\Batches\Services\LivePollService;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\LivePollAuthorizationException;
use App\Exceptions\LivePollStateException;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeLiveBatchSession(Tenant $tenant, User $instructor, array $sessionAttrs = []): BatchSession
{
    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Mechanics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Live class', 'type' => 'live_session', 'position' => 0]);
    $batch = Batch::create([
        'course_id' => $course->id,
        'instructor_id' => $instructor->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-'.uniqid(),
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'draft',
    ]);

    return BatchSession::create(array_merge([
        'batch_id' => $batch->id,
        'lesson_id' => $lesson->id,
        'title' => 'Kinematics recap',
        'starts_at' => now()->addDay(),
        'duration_minutes' => 60,
        'position' => 0,
        'live_provider' => 'external_link',
        'provider_meeting_ref' => 'https://example.test/meet',
    ], $sessionAttrs));
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->instructor->assignRole('instructor');

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');

    $this->service = new LivePollService;
});

it('lets an instructor create a poll with options', function () {
    $session = makeLiveBatchSession($this->tenant, $this->instructor);

    $poll = $this->service->create($session, $this->instructor, 'Which topic next?', ['Kinematics', 'Dynamics']);

    expect($poll->status)->toBe('draft')
        ->and($poll->options)->toHaveCount(2)
        ->and($poll->options->pluck('label')->all())->toBe(['Kinematics', 'Dynamics']);
});

it('refuses a student creating a poll', function () {
    $session = makeLiveBatchSession($this->tenant, $this->instructor);

    expect(fn () => $this->service->create($session, $this->student, 'Which topic next?', ['A', 'B']))
        ->toThrow(LivePollAuthorizationException::class);
});

it('refuses a poll with fewer than 2 options', function () {
    $session = makeLiveBatchSession($this->tenant, $this->instructor);

    expect(fn () => $this->service->create($session, $this->instructor, 'Which topic next?', ['Only one']))
        ->toThrow(InvalidArgumentException::class);
});

it('opens a draft poll only while the session is live', function () {
    $liveSession = makeLiveBatchSession($this->tenant, $this->instructor, ['starts_at' => now()->subMinutes(5), 'duration_minutes' => 60]);
    $poll = $this->service->create($liveSession, $this->instructor, 'Q?', ['A', 'B']);

    $this->service->open($poll, $this->instructor);

    expect($poll->fresh()->status)->toBe('open')
        ->and($poll->fresh()->opened_at)->not->toBeNull();
});

it('refuses to open a poll while the session is not yet live', function () {
    $scheduledSession = makeLiveBatchSession($this->tenant, $this->instructor);
    $poll = $this->service->create($scheduledSession, $this->instructor, 'Q?', ['A', 'B']);

    expect(fn () => $this->service->open($poll, $this->instructor))
        ->toThrow(LivePollStateException::class);
});

it('refuses to re-open an already-open poll', function () {
    $liveSession = makeLiveBatchSession($this->tenant, $this->instructor, ['starts_at' => now()->subMinutes(5), 'duration_minutes' => 60]);
    $poll = $this->service->create($liveSession, $this->instructor, 'Q?', ['A', 'B']);
    $this->service->open($poll, $this->instructor);

    expect(fn () => $this->service->open($poll, $this->instructor))
        ->toThrow(LivePollStateException::class);
});

it('closes an open poll', function () {
    $liveSession = makeLiveBatchSession($this->tenant, $this->instructor, ['starts_at' => now()->subMinutes(5), 'duration_minutes' => 60]);
    $poll = $this->service->create($liveSession, $this->instructor, 'Q?', ['A', 'B']);
    $this->service->open($poll, $this->instructor);

    $this->service->close($poll, $this->instructor);

    expect($poll->fresh()->status)->toBe('closed')
        ->and($poll->fresh()->closed_at)->not->toBeNull();
});

it('refuses to close a poll that is not open', function () {
    $session = makeLiveBatchSession($this->tenant, $this->instructor);
    $poll = $this->service->create($session, $this->instructor, 'Q?', ['A', 'B']);

    expect(fn () => $this->service->close($poll, $this->instructor))
        ->toThrow(LivePollStateException::class);
});

it('refuses a student opening or closing a poll', function () {
    $liveSession = makeLiveBatchSession($this->tenant, $this->instructor, ['starts_at' => now()->subMinutes(5), 'duration_minutes' => 60]);
    $poll = $this->service->create($liveSession, $this->instructor, 'Q?', ['A', 'B']);

    expect(fn () => $this->service->open($poll, $this->student))
        ->toThrow(LivePollAuthorizationException::class);

    $this->service->open($poll, $this->instructor);

    expect(fn () => $this->service->close($poll, $this->student))
        ->toThrow(LivePollAuthorizationException::class);
});
