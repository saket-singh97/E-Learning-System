<?php

use App\Domains\Content\Livewire\LessonQuestions;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\LessonQuestion;
use App\Domains\Content\Models\LessonQuestionReply;
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
    // text, not video — proves the Q&A thread is not video/live_session-only.
    $this->lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Reading', 'type' => 'text', 'position' => 0, 'text_body' => 'Hello']);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');
});

it('lets a viewer with access post a question', function () {
    Livewire::actingAs($this->student)
        ->test(LessonQuestions::class, ['lesson' => $this->lesson])
        ->set('newQuestionBody', 'How does this work?')
        ->call('askQuestion')
        ->assertSee('How does this work?');

    expect(LessonQuestion::where('lesson_id', $this->lesson->id)->where('user_id', $this->student->id)->exists())->toBeTrue();
});

it('no-ops for a guest', function () {
    Livewire::test(LessonQuestions::class, ['lesson' => $this->lesson])
        ->set('newQuestionBody', 'How does this work?')
        ->call('askQuestion');

    expect(LessonQuestion::where('lesson_id', $this->lesson->id)->exists())->toBeFalse();
});

it('reuses LessonAccess::canStream() — denies posting when a prerequisite is unmet', function () {
    $prerequisite = Lesson::create(['course_section_id' => $this->lesson->course_section_id, 'title' => 'Intro', 'type' => 'text', 'position' => 1, 'text_body' => 'Intro'.'.']);
    app(PrerequisiteGraph::class)->addPrerequisite($this->lesson, $prerequisite);

    Livewire::actingAs($this->student)
        ->test(LessonQuestions::class, ['lesson' => $this->lesson])
        ->set('newQuestionBody', 'How does this work?')
        ->call('askQuestion');

    expect(LessonQuestion::where('lesson_id', $this->lesson->id)->exists())->toBeFalse();
});

it('lets a viewer with access reply to a question', function () {
    $question = LessonQuestion::create(['lesson_id' => $this->lesson->id, 'user_id' => $this->student->id, 'body' => 'How does this work?']);

    $replier = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $replier->assignRole('student');

    Livewire::actingAs($replier)
        ->test(LessonQuestions::class, ['lesson' => $this->lesson])
        ->set("replyBodies.{$question->id}", 'Here is how.')
        ->call('reply', $question->id)
        ->assertSee('Here is how.');

    expect(LessonQuestionReply::where('lesson_question_id', $question->id)->where('user_id', $replier->id)->exists())->toBeTrue();
});

it('no-ops a reply for a guest', function () {
    $question = LessonQuestion::create(['lesson_id' => $this->lesson->id, 'user_id' => $this->student->id, 'body' => 'How does this work?']);

    Livewire::test(LessonQuestions::class, ['lesson' => $this->lesson])
        ->set("replyBodies.{$question->id}", 'Here is how.')
        ->call('reply', $question->id);

    expect(LessonQuestionReply::where('lesson_question_id', $question->id)->exists())->toBeFalse();
});
