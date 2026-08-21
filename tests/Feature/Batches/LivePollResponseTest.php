<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Batches\Models\LivePoll;
use App\Domains\Batches\Models\LivePollOption;
use App\Domains\Batches\Models\LivePollResponse as LivePollResponseModel;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Livewire\LivePollResponse;
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

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Live Q&A', 'type' => 'live_session', 'position' => 0]);

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
        'lesson_id' => $lesson->id,
        'starts_at' => '2026-09-08 10:00:00',
        'duration_minutes' => 60,
        'position' => 0,
        'live_provider' => 'external_link',
        'provider_meeting_ref' => 'https://meet.example.com/room-1',
        'room_created_at' => now(),
    ]);

    $this->poll = LivePoll::create([
        'batch_session_id' => $this->session->id,
        'question' => 'Which topic next?',
        'status' => 'open',
        'opened_at' => now(),
    ]);
    $this->optionA = LivePollOption::create(['live_poll_id' => $this->poll->id, 'label' => 'Kinematics', 'position' => 0]);
    $this->optionB = LivePollOption::create(['live_poll_id' => $this->poll->id, 'label' => 'Dynamics', 'position' => 1]);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');

    $this->travelTo(Carbon\Carbon::parse('2026-09-08 10:30:00'));
});

it('lets an enrolled, live-window student submit a response', function () {
    Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $this->student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    Livewire::actingAs($this->student)
        ->test(LivePollResponse::class, ['session' => $this->session])
        ->call('submit', $this->optionA->id);

    expect(LivePollResponseModel::where('live_poll_id', $this->poll->id)->where('user_id', $this->student->id)->first())
        ->not->toBeNull()
        ->and(LivePollResponseModel::where('live_poll_id', $this->poll->id)->where('user_id', $this->student->id)->first()->live_poll_option_id)
        ->toBe($this->optionA->id);
});

it('does not record a second response from the same student', function () {
    Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $this->student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    $component = Livewire::actingAs($this->student)
        ->test(LivePollResponse::class, ['session' => $this->session]);

    $component->call('submit', $this->optionA->id);
    $component->call('submit', $this->optionB->id);

    expect(LivePollResponseModel::where('live_poll_id', $this->poll->id)->where('user_id', $this->student->id)->count())
        ->toBe(1);
});

it('refuses a student with no active enrolment', function () {
    Livewire::actingAs($this->student)
        ->test(LivePollResponse::class, ['session' => $this->session])
        ->call('submit', $this->optionA->id);

    expect(LivePollResponseModel::where('live_poll_id', $this->poll->id)->count())->toBe(0);
});

it('does nothing when no poll is open', function () {
    $this->poll->update(['status' => 'closed']);

    Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $this->student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    Livewire::actingAs($this->student)
        ->test(LivePollResponse::class, ['session' => $this->session])
        ->call('submit', $this->optionA->id);

    expect(LivePollResponseModel::query()->count())->toBe(0);
});

it('shows the confirmation message once the student has responded', function () {
    Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $this->student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    Livewire::actingAs($this->student)
        ->test(LivePollResponse::class, ['session' => $this->session])
        ->call('submit', $this->optionA->id)
        ->assertSee('Response recorded');
});
