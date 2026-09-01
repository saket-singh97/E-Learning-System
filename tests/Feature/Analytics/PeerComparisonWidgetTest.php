<?php

use App\Domains\Analytics\Livewire\PeerComparisonWidget;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Batches\Models\Batch;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Reuses peerComparisonAttempt()/peerComparisonAnswer()/peerComparisonEnrol()
// already defined in the sibling PeerComparisonServiceTest.php — same
// directory, loaded into the same Pest run, same precedent WeakTopicsTest
// already relies on for WeakTopicDetectionServiceTest's own helpers.

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid()]);
    $section = CourseSection::create(['course_id' => $this->course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Velocity', 'type' => 'video', 'position' => 0]);
    $this->quiz = Quiz::create(['lesson_id' => $lesson->id, 'title' => 'Quiz', 'status' => 'published', 'blueprint' => [], 'time_limit_minutes' => 20, 'max_attempts' => 5]);

    $this->batch = Batch::create([
        'course_id' => $this->course->id,
        'title' => 'Batch A',
        'slug' => 'batch-a-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'draft',
    ]);
});

it('shows the viewer\'s own top-N% on a weak concept, computed from real seeded classmates', function () {
    $viewer = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $classmateTop = User::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Classmate Name']);
    $classmateBottom = User::factory()->create(['tenant_id' => $this->tenant->id]);

    peerComparisonEnrol($this->batch, $viewer);
    peerComparisonEnrol($this->batch, $classmateTop);
    peerComparisonEnrol($this->batch, $classmateBottom);

    // Viewer: 1/3 correct (ratio 0.33, weak — below the 0.6 threshold).
    $viewerAttempt = peerComparisonAttempt($this->quiz->id, $viewer->id);
    peerComparisonAnswer($this->course->id, $viewerAttempt->id, 'Kinematics', true);
    peerComparisonAnswer($this->course->id, $viewerAttempt->id, 'Kinematics', false);
    peerComparisonAnswer($this->course->id, $viewerAttempt->id, 'Kinematics', false);

    // Classmate top: 3/3 (ratio 1.0). Classmate bottom: 0/3 (ratio 0.0) —
    // the one the viewer actually beats.
    $topAttempt = peerComparisonAttempt($this->quiz->id, $classmateTop->id);
    peerComparisonAnswer($this->course->id, $topAttempt->id, 'Kinematics', true);
    peerComparisonAnswer($this->course->id, $topAttempt->id, 'Kinematics', true);
    peerComparisonAnswer($this->course->id, $topAttempt->id, 'Kinematics', true);

    $bottomAttempt = peerComparisonAttempt($this->quiz->id, $classmateBottom->id);
    peerComparisonAnswer($this->course->id, $bottomAttempt->id, 'Kinematics', false);
    peerComparisonAnswer($this->course->id, $bottomAttempt->id, 'Kinematics', false);
    peerComparisonAnswer($this->course->id, $bottomAttempt->id, 'Kinematics', false);

    $this->actingAs($viewer);

    // Viewer beats 1 of 3 (the bottom classmate) => percentile 33.3,
    // top_percent = 100 - 33.3 = 66.7.
    Livewire::test(PeerComparisonWidget::class, ['course' => $this->course])
        ->assertSee('Kinematics')
        ->assertSee('top 66.7%')
        ->assertDontSee('Classmate Name');
});

it('renders nothing and never errors for a viewer with no active enrolment in the course', function () {
    $viewer = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->actingAs($viewer);

    Livewire::test(PeerComparisonWidget::class, ['course' => $this->course])
        ->assertDontSee('top ');
});
