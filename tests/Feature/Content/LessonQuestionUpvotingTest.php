<?php

use App\Domains\Content\Livewire\LessonQuestions;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\LessonQuestion;
use App\Domains\Content\Models\LessonQuestionVote;
use App\Domains\Content\Services\PrerequisiteGraph;
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

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $this->lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Reading', 'type' => 'text', 'position' => 0, 'text_body' => 'Hello']);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');

    $this->question = LessonQuestion::create(['lesson_id' => $this->lesson->id, 'user_id' => $this->student->id, 'body' => 'How does this work?']);
});

it('toggles an upvote on and off for a viewer with access', function () {
    $voter = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $voter->assignRole('student');

    Livewire::actingAs($voter)
        ->test(LessonQuestions::class, ['lesson' => $this->lesson])
        ->call('toggleUpvote', $this->question->id);

    expect(LessonQuestionVote::where('lesson_question_id', $this->question->id)->where('user_id', $voter->id)->exists())->toBeTrue();

    Livewire::actingAs($voter)
        ->test(LessonQuestions::class, ['lesson' => $this->lesson])
        ->call('toggleUpvote', $this->question->id);

    expect(LessonQuestionVote::where('lesson_question_id', $this->question->id)->where('user_id', $voter->id)->exists())->toBeFalse();
});

it('no-ops an upvote for a guest', function () {
    Livewire::test(LessonQuestions::class, ['lesson' => $this->lesson])
        ->call('toggleUpvote', $this->question->id);

    expect(LessonQuestionVote::where('lesson_question_id', $this->question->id)->exists())->toBeFalse();
});

it('denies an upvote when LessonAccess::canStream() denies the lesson (unmet prerequisite)', function () {
    $prerequisite = Lesson::create(['course_section_id' => $this->lesson->course_section_id, 'title' => 'Intro', 'type' => 'text', 'position' => 1, 'text_body' => 'Intro.']);
    app(PrerequisiteGraph::class)->addPrerequisite($this->lesson, $prerequisite);

    Livewire::actingAs($this->student)
        ->test(LessonQuestions::class, ['lesson' => $this->lesson])
        ->call('toggleUpvote', $this->question->id);

    expect(LessonQuestionVote::where('lesson_question_id', $this->question->id)->exists())->toBeFalse();
});

it('lets an instructor mark a question resolved', function () {
    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');

    Livewire::actingAs($instructor)
        ->test(LessonQuestions::class, ['lesson' => $this->lesson])
        ->call('markResolved', $this->question->id)
        ->assertSee('Resolved');

    expect($this->question->fresh()->resolved_at)->not->toBeNull();
});

it('does not let a student mark a question resolved', function () {
    Livewire::actingAs($this->student)
        ->test(LessonQuestions::class, ['lesson' => $this->lesson])
        ->call('markResolved', $this->question->id);

    expect($this->question->fresh()->resolved_at)->toBeNull();
});

it('does not let a guest mark a question resolved', function () {
    Livewire::test(LessonQuestions::class, ['lesson' => $this->lesson])
        ->call('markResolved', $this->question->id);

    expect($this->question->fresh()->resolved_at)->toBeNull();
});
