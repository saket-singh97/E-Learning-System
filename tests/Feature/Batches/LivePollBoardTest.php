<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Batches\Models\LivePoll;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Livewire\LivePollBoard;
use App\Livewire\LivePollResponse;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Mechanics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Live class', 'type' => 'live_session', 'position' => 0]);
    $this->batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-'.uniqid(),
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    $this->session = BatchSession::create([
        'batch_id' => $this->batch->id,
        'lesson_id' => $lesson->id,
        'starts_at' => now()->subMinutes(5),
        'duration_minutes' => 60,
        'position' => 0,
        'live_provider' => 'external_link',
        'provider_meeting_ref' => 'https://example.test/meet',
    ]);

    $this->instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->instructor->assignRole('instructor');

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');
    Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $this->student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);
});

it('lets an instructor create a poll with filtered blank options', function () {
    Livewire::actingAs($this->instructor)
        ->test(LivePollBoard::class, ['session' => $this->session])
        ->set('question', 'Which topic next?')
        ->set('optionInputs', ['Kinematics', '', 'Dynamics'])
        ->call('createPoll');

    $poll = LivePoll::where('batch_session_id', $this->session->id)->first();

    expect($poll)->not->toBeNull()
        ->and($poll->status)->toBe('draft')
        ->and($poll->options)->toHaveCount(2)
        ->and($poll->options->pluck('label')->all())->toBe(['Kinematics', 'Dynamics']);
});

it('refuses a student creating a poll', function () {
    Livewire::actingAs($this->student)
        ->test(LivePollBoard::class, ['session' => $this->session])
        ->set('question', 'Which topic next?')
        ->set('optionInputs', ['A', 'B'])
        ->call('createPoll');

    expect(LivePoll::where('batch_session_id', $this->session->id)->count())->toBe(0);
});

it('opens and closes a poll through the board, live in results as students respond', function () {
    $poll = app(App\Domains\Batches\Services\LivePollService::class)
        ->create($this->session, $this->instructor, 'Which topic next?', ['Kinematics', 'Dynamics']);

    Livewire::actingAs($this->instructor)
        ->test(LivePollBoard::class, ['session' => $this->session])
        ->call('openPoll', $poll->id)
        ->assertSee('open');

    expect($poll->fresh()->status)->toBe('open');

    Livewire::actingAs($this->student)
        ->test(LivePollResponse::class, ['session' => $this->session])
        ->call('submit', $poll->options->first()->id);

    $board = Livewire::actingAs($this->instructor)
        ->test(LivePollBoard::class, ['session' => $this->session]);

    $board->assertSee('1 response(s)');

    $board->call('closePoll', $poll->id);

    expect($poll->fresh()->status)->toBe('closed');
});

it('refuses a student closing a poll', function () {
    $poll = app(App\Domains\Batches\Services\LivePollService::class)
        ->create($this->session, $this->instructor, 'Q?', ['A', 'B']);
    app(App\Domains\Batches\Services\LivePollService::class)->open($poll, $this->instructor);

    Livewire::actingAs($this->student)
        ->test(LivePollBoard::class, ['session' => $this->session])
        ->call('closePoll', $poll->id);

    expect($poll->fresh()->status)->toBe('open');
});
