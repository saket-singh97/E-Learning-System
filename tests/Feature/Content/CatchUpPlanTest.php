<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Livewire\CatchUpPlan;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;
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

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Mechanics', 'position' => 0]);
    $this->lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Velocity', 'type' => 'text', 'position' => 0]);

    $this->batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-batch-'.uniqid(),
        'starts_on' => now()->subMonth()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    BatchSession::create(['batch_id' => $this->batch->id, 'lesson_id' => $this->lesson->id, 'starts_at' => now()->subDay(), 'duration_minutes' => 60, 'position' => 0]);

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

it('lists an enrolled students incomplete taught lessons', function () {
    Livewire::actingAs($this->student)
        ->test(CatchUpPlan::class, ['batch' => $this->batch])
        ->assertSee('Velocity');
});

it('shows the caught-up message once the lesson is complete', function () {
    VideoWatchEvent::create(['lesson_id' => $this->lesson->id, 'user_id' => $this->student->id, 'start_s' => 0, 'end_s' => 10]);

    Livewire::actingAs($this->student)
        ->test(CatchUpPlan::class, ['batch' => $this->batch])
        ->assertSee('all caught up');
});

it('404s a non-enrolled user', function () {
    $stranger = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $stranger->assignRole('student');

    Livewire::actingAs($stranger)
        ->test(CatchUpPlan::class, ['batch' => $this->batch])
        ->assertStatus(404);
});

it('renders via the hosting route for an enrolled student', function () {
    $this->actingAs($this->student)
        ->get(route('batch.catch-up', ['batch' => $this->batch->id, 'subdomain' => 'physics']))
        ->assertOk()
        ->assertSee('Velocity');
});
