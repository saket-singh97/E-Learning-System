<?php

use App\Domains\AI\Livewire\WeakTopics;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('shows the viewers own weak topics with a link to the covering lesson', function () {
    [$tenant, $student, $course, , $lesson, $attempt] = weakTopicFixture();
    weakTopicQuestionAndAnswer($course->id, $attempt->id, 'Kinematics', false);

    \App\Domains\AI\Models\LessonArtifact::create([
        'lesson_id' => $lesson->id,
        'version' => 1,
        'source_asset_version' => 'v1',
        'status' => 'ready',
        'concepts' => [['name' => 'Kinematics', 'confidence' => 0.9, 'chunk_idxs' => [0]]],
    ]);

    $this->actingAs($student);

    Livewire::test(WeakTopics::class, ['course' => $course])
        ->assertSee('Kinematics')
        ->assertSee($lesson->title);
});

it('never shows a classmates weak topics, only the viewers own', function () {
    [$tenant, $student, $course, , , $attempt] = weakTopicFixture();
    weakTopicQuestionAndAnswer($course->id, $attempt->id, 'Own Weak Concept', false);

    $classmate = \App\Models\User::factory()->create(['tenant_id' => $tenant->id]);
    $classmateAttempt = \App\Domains\Assessments\Models\QuizAttempt::create([
        'quiz_id' => \App\Domains\Assessments\Models\Quiz::first()->id,
        'user_id' => $classmate->id,
        'question_ids' => [],
        'status' => 'submitted',
        'started_at' => now(),
        'expires_at' => now()->addHour(),
        'max_score' => 10,
    ]);
    weakTopicQuestionAndAnswer($course->id, $classmateAttempt->id, 'Classmates Weak Concept', false);

    $this->actingAs($student);

    Livewire::test(WeakTopics::class, ['course' => $course])
        ->assertSee('Own Weak Concept')
        ->assertDontSee('Classmates Weak Concept');
});

it('shows an empty state for a guest instead of erroring', function () {
    [, , $course] = weakTopicFixture();

    Livewire::test(WeakTopics::class, ['course' => $course])
        ->assertSee('Log in to see your weak topics');
});
