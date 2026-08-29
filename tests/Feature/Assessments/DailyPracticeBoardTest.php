<?php

use App\Domains\AI\Models\LessonArtifact;
use App\Domains\Assessments\Livewire\DailyPracticeBoard;
use App\Domains\Assessments\Models\DailyPracticeSet;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\Enrolment;
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

    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Velocity', 'type' => 'live_session', 'position' => 0]);

    LessonArtifact::create([
        'lesson_id' => $lesson->id,
        'version' => 1,
        'source_asset_version' => 'v1',
        'status' => 'ready',
        'seed_questions' => [['type' => 'mcq', 'stem' => 'Q?', 'options' => ['A', 'B'], 'answer' => 'A']],
    ]);

    $this->batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-batch-'.uniqid(),
        'starts_on' => now()->subMonth()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);

    BatchSession::create([
        'batch_id' => $this->batch->id,
        'lesson_id' => $lesson->id,
        'starts_at' => now()->subDay(),
        'duration_minutes' => 60,
        'position' => 0,
    ]);

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

it('shows an enrolled student todays practice set', function () {
    Livewire::actingAs($this->student)
        ->test(DailyPracticeBoard::class, ['batch' => $this->batch])
        ->assertSee('Q?');

    $set = DailyPracticeSet::where('batch_id', $this->batch->id)->sole();
    expect($set->question_ids)->toHaveCount(1);
});

it('is idempotent when the page is visited twice the same day', function () {
    Livewire::actingAs($this->student)->test(DailyPracticeBoard::class, ['batch' => $this->batch]);
    Livewire::actingAs($this->student)->test(DailyPracticeBoard::class, ['batch' => $this->batch]);

    expect(DailyPracticeSet::where('batch_id', $this->batch->id)->count())->toBe(1);
});

it('404s a non-enrolled user', function () {
    $stranger = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $stranger->assignRole('student');

    Livewire::actingAs($stranger)
        ->test(DailyPracticeBoard::class, ['batch' => $this->batch])
        ->assertStatus(404);
});
