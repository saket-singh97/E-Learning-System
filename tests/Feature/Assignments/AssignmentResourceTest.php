<?php

use App\Domains\Assignments\Filament\Resources\Assignments\AssignmentResource;
use App\Domains\Assignments\Filament\Resources\Assignments\Pages\CreateAssignment;
use App\Domains\Assignments\Filament\Resources\Assignments\Pages\EditAssignment;
use App\Domains\Assignments\Filament\Resources\Assignments\Pages\ListAssignments;
use App\Domains\Assignments\Models\Assignment;
use App\Domains\Assignments\Models\AssignmentSubmission;
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

    $this->course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $this->section = CourseSection::create(['course_id' => $this->course->id, 'title' => 'Kinematics', 'position' => 0]);
    $this->assignmentLesson = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'Kinematics Homework', 'type' => 'assignment', 'position' => 0]);

    $this->actingAs($this->instructor)
        ->get(AssignmentResource::getUrl('create', ['subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();
});

it('creates an assignment anchored to an assignment-type lesson', function () {
    Livewire::test(CreateAssignment::class)
        ->fillForm([
            'lesson_id' => $this->assignmentLesson->id,
            'title' => 'Kinematics Problem Set 1',
            'instructions' => 'Solve all five problems, show your work.',
            'due_at' => now()->addWeek()->format('Y-m-d H:i:s'),
            'max_marks' => 50,
            'allowed_file_types' => ['pdf', 'jpg'],
            'max_file_size_kb' => 5000,
            'rubric' => [
                ['criterion' => 'Working shown', 'max_points' => 20],
                ['criterion' => 'Final answers correct', 'max_points' => 30],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $assignment = Assignment::sole();

    expect($assignment->lesson_id)->toBe($this->assignmentLesson->id)
        ->and($assignment->title)->toBe('Kinematics Problem Set 1')
        ->and($assignment->max_marks)->toEqual(50)
        ->and($assignment->allowed_file_types)->toBe(['pdf', 'jpg'])
        ->and($assignment->max_file_size_kb)->toBe(5000)
        ->and($assignment->rubric)->toHaveCount(2)
        ->and($assignment->rubric[0]['criterion'])->toBe('Working shown')
        ->and($assignment->rubric[0]['id'])->not->toBeEmpty();
});

it('creates a marks-only assignment when the rubric is left empty', function () {
    Livewire::test(CreateAssignment::class)
        ->fillForm([
            'lesson_id' => $this->assignmentLesson->id,
            'title' => 'Marks-only homework',
            'due_at' => now()->addWeek()->format('Y-m-d H:i:s'),
            'max_marks' => 20,
            'allowed_file_types' => ['pdf'],
            'max_file_size_kb' => 2000,
            'rubric' => [],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $assignment = Assignment::sole();

    expect(blank($assignment->rubric))->toBeTrue();
});

it('rejects a missing due date, max marks, or file type list', function () {
    Livewire::test(CreateAssignment::class)
        ->fillForm([
            'lesson_id' => $this->assignmentLesson->id,
            'title' => 'Incomplete',
            'due_at' => null,
            'max_marks' => null,
            'allowed_file_types' => [],
            'max_file_size_kb' => 2000,
        ])
        ->call('create')
        ->assertHasFormErrors(['due_at', 'max_marks', 'allowed_file_types']);

    expect(Assignment::count())->toBe(0);
});

it('edits an assignment', function () {
    $assignment = Assignment::create([
        'lesson_id' => $this->assignmentLesson->id,
        'title' => 'Draft homework',
        'due_at' => now()->addWeek(),
        'max_marks' => 10,
        'allowed_file_types' => ['pdf'],
        'max_file_size_kb' => 2000,
    ]);

    Livewire::test(EditAssignment::class, ['record' => $assignment->id])
        ->fillForm(['max_marks' => 25])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($assignment->fresh()->max_marks)->toEqual(25);
});

it('hides delete for an assignment that already has submissions, to avoid destroying graded history', function () {
    $assignment = Assignment::create([
        'lesson_id' => $this->assignmentLesson->id,
        'title' => 'Submitted homework',
        'due_at' => now()->addWeek(),
        'max_marks' => 10,
        'allowed_file_types' => ['pdf'],
        'max_file_size_kb' => 2000,
    ]);

    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);

    AssignmentSubmission::create([
        'assignment_id' => $assignment->id,
        'user_id' => $student->id,
        'version_number' => 1,
        'files' => [['disk_path' => 'a.pdf', 'original_name' => 'a.pdf', 'size_kb' => 100]],
        'submitted_at' => now(),
    ]);

    $this->actingAs($this->instructor)
        ->get(AssignmentResource::getUrl('index', ['subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();

    Livewire::test(ListAssignments::class)
        ->assertTableActionHidden('delete', $assignment);
});

it('allows deleting an assignment with no submissions yet', function () {
    $assignment = Assignment::create([
        'lesson_id' => $this->assignmentLesson->id,
        'title' => 'Untouched homework',
        'due_at' => now()->addWeek(),
        'max_marks' => 10,
        'allowed_file_types' => ['pdf'],
        'max_file_size_kb' => 2000,
    ]);

    $this->actingAs($this->instructor)
        ->get(AssignmentResource::getUrl('index', ['subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();

    Livewire::test(ListAssignments::class)
        ->assertTableActionVisible('delete', $assignment);
});
