<?php

use App\Domains\Assignments\Filament\Resources\AssignmentSubmissions\AssignmentSubmissionResource;
use App\Domains\Assignments\Filament\Resources\AssignmentSubmissions\Pages\ListAssignmentSubmissions;
use App\Domains\Assignments\Models\Assignment;
use App\Domains\Assignments\Models\AssignmentSubmission;
use App\Domains\Assignments\Services\AssignmentGradingService;
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

    $this->instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->instructor->assignRole('instructor');

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');

    $this->course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $this->section = CourseSection::create(['course_id' => $this->course->id, 'title' => 'Kinematics', 'position' => 0]);
    $this->lesson = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'Homework 1', 'type' => 'assignment', 'position' => 0]);

    $this->actingAs($this->instructor)
        ->get(AssignmentSubmissionResource::getUrl('index', ['subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();
});

it('grades a marks-only submission and stamps grader/timestamp', function () {
    $assignment = Assignment::create([
        'lesson_id' => $this->lesson->id,
        'title' => 'Marks-only homework',
        'due_at' => now()->addWeek(),
        'max_marks' => 20,
        'allowed_file_types' => ['pdf'],
        'max_file_size_kb' => 2000,
    ]);

    $submission = AssignmentSubmission::create([
        'assignment_id' => $assignment->id,
        'user_id' => $this->student->id,
        'version_number' => 1,
        'files' => [['disk_path' => 'a.pdf', 'original_name' => 'a.pdf', 'size_kb' => 100]],
        'submitted_at' => now(),
        'is_late' => false,
    ]);

    Livewire::test(ListAssignmentSubmissions::class)
        ->callTableAction('grade', $submission, data: ['marks_awarded' => 15, 'feedback' => 'Good work'])
        ->assertHasNoTableActionErrors();

    $submission->refresh();

    expect((float) $submission->marks_awarded)->toBe(15.0)
        ->and($submission->feedback)->toBe('Good work')
        ->and($submission->graded_by_user_id)->toBe($this->instructor->id)
        ->and($submission->graded_at)->not->toBeNull()
        ->and($submission->rubric_scores)->toBeNull();
});

it('grades a rubric-based submission, deriving marks_awarded from the criterion scores', function () {
    $assignment = Assignment::create([
        'lesson_id' => $this->lesson->id,
        'title' => 'Rubric homework',
        'due_at' => now()->addWeek(),
        'max_marks' => 50,
        'allowed_file_types' => ['pdf'],
        'max_file_size_kb' => 2000,
        'rubric' => [
            ['criterion' => 'Working shown', 'max_points' => 20],
            ['criterion' => 'Final answer correct', 'max_points' => 30],
        ],
    ]);
    $assignment->refresh();

    $submission = AssignmentSubmission::create([
        'assignment_id' => $assignment->id,
        'user_id' => $this->student->id,
        'version_number' => 1,
        'files' => [['disk_path' => 'a.pdf', 'original_name' => 'a.pdf', 'size_kb' => 100]],
        'submitted_at' => now(),
        'is_late' => false,
    ]);

    $workingId = $assignment->rubric[0]['id'];
    $answerId = $assignment->rubric[1]['id'];

    Livewire::test(ListAssignmentSubmissions::class)
        ->callTableAction('grade', $submission, data: [
            'rubric_scores' => [
                $workingId => 15,
                $answerId => 25,
            ],
            'feedback' => 'Nearly there',
        ])
        ->assertHasNoTableActionErrors();

    $submission->refresh();

    expect((float) $submission->marks_awarded)->toBe(40.0)
        ->and($submission->rubric_scores[$workingId])->toEqual(15)
        ->and($submission->rubric_scores[$answerId])->toEqual(25)
        ->and($submission->graded_by_user_id)->toBe($this->instructor->id);
});

it('hides the grade action once a submission is already graded', function () {
    $assignment = Assignment::create([
        'lesson_id' => $this->lesson->id,
        'title' => 'Homework',
        'due_at' => now()->addWeek(),
        'max_marks' => 20,
        'allowed_file_types' => ['pdf'],
        'max_file_size_kb' => 2000,
    ]);

    $submission = AssignmentSubmission::create([
        'assignment_id' => $assignment->id,
        'user_id' => $this->student->id,
        'version_number' => 1,
        'files' => [['disk_path' => 'a.pdf', 'original_name' => 'a.pdf', 'size_kb' => 100]],
        'submitted_at' => now(),
        'is_late' => false,
        'marks_awarded' => 10,
        'graded_by_user_id' => $this->instructor->id,
        'graded_at' => now(),
    ]);

    Livewire::test(ListAssignmentSubmissions::class)
        ->assertTableActionHidden('grade', $submission);
});

it('rejects a rubric score above that criterion own max points', function () {
    $assignment = Assignment::create([
        'lesson_id' => $this->lesson->id,
        'title' => 'Rubric homework',
        'due_at' => now()->addWeek(),
        'max_marks' => 20,
        'allowed_file_types' => ['pdf'],
        'max_file_size_kb' => 2000,
        'rubric' => [
            ['criterion' => 'Working shown', 'max_points' => 20],
        ],
    ]);
    $assignment->refresh();

    $submission = AssignmentSubmission::create([
        'assignment_id' => $assignment->id,
        'user_id' => $this->student->id,
        'version_number' => 1,
        'files' => [['disk_path' => 'a.pdf', 'original_name' => 'a.pdf', 'size_kb' => 100]],
        'submitted_at' => now(),
        'is_late' => false,
    ]);

    $criterionId = $assignment->rubric[0]['id'];

    Livewire::test(ListAssignmentSubmissions::class)
        ->callTableAction('grade', $submission, data: [
            'rubric_scores' => [$criterionId => 999],
        ])
        ->assertHasTableActionErrors(["rubric_scores.{$criterionId}"]);

    expect($submission->fresh()->graded_at)->toBeNull();
});

it('refuses to grade with a rubric criterion id that does not belong to the assignment', function () {
    $assignment = Assignment::create([
        'lesson_id' => $this->lesson->id,
        'title' => 'Rubric homework',
        'due_at' => now()->addWeek(),
        'max_marks' => 20,
        'allowed_file_types' => ['pdf'],
        'max_file_size_kb' => 2000,
        'rubric' => [
            ['criterion' => 'Working shown', 'max_points' => 20],
        ],
    ]);

    $submission = AssignmentSubmission::create([
        'assignment_id' => $assignment->id,
        'user_id' => $this->student->id,
        'version_number' => 1,
        'files' => [['disk_path' => 'a.pdf', 'original_name' => 'a.pdf', 'size_kb' => 100]],
        'submitted_at' => now(),
        'is_late' => false,
    ]);

    app(AssignmentGradingService::class)->grade(
        $submission,
        $this->instructor,
        null,
        null,
        ['not-a-real-criterion-id' => 10],
    );
})->throws(App\Exceptions\UnknownRubricCriterionException::class);
