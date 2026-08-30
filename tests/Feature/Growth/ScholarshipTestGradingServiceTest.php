<?php

use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Growth\Models\ScholarshipTest;
use App\Domains\Growth\Models\ScholarshipTestRegistration;
use App\Domains\Growth\Services\ScholarshipTestGradingService;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Quiz Lesson', 'type' => 'quiz', 'position' => 0]);
    $quiz = Quiz::create([
        'lesson_id' => $lesson->id,
        'title' => 'Scholarship Quiz',
        'blueprint' => [],
        'time_limit_minutes' => 60,
        'max_attempts' => 1,
    ]);

    $this->test = ScholarshipTest::create([
        'course_id' => $course->id,
        'title' => 'National Scholarship Test',
        'quiz_id' => $quiz->id,
        'opens_at' => now()->subDay(),
        'closes_at' => now()->addWeek(),
    ]);

    $this->mcq = Question::create([
        'course_id' => $course->id,
        'type' => 'mcq',
        'stem' => 'What is 2 + 2?',
        'options' => ['3', '4', '5'],
        'correct_answer' => '4',
        'difficulty' => 'easy',
        'status' => 'approved',
        'source' => 'manual',
    ]);

    $this->numerical = Question::create([
        'course_id' => $course->id,
        'type' => 'numerical',
        'stem' => 'Value of pi to 1dp?',
        'correct_answer' => ['value' => 3.1, 'tolerance' => 0.1],
        'difficulty' => 'easy',
        'status' => 'approved',
        'source' => 'manual',
    ]);

    $this->registration = ScholarshipTestRegistration::create([
        'scholarship_test_id' => $this->test->id,
        'name' => 'Asha',
        'email' => 'asha@example.com',
    ]);

    $this->service = app(ScholarshipTestGradingService::class);
});

it('grades correctly against a known answer key', function () {
    $graded = $this->service->grade($this->registration, [
        $this->mcq->id => '4',
        $this->numerical->id => 3.15,
    ]);

    expect((float) $graded->score)->toBe(2.0);
});

it('scores a wrong answer as zero for that question', function () {
    $graded = $this->service->grade($this->registration, [
        $this->mcq->id => '3',
        $this->numerical->id => 3.15,
    ]);

    expect((float) $graded->score)->toBe(1.0);
});

it('ignores a question id from a different course', function () {
    $otherCourse = Course::create(['title' => 'Chemistry', 'slug' => 'chemistry']);
    $foreignQuestion = Question::create([
        'course_id' => $otherCourse->id,
        'type' => 'mcq',
        'stem' => 'Foreign question',
        'correct_answer' => 'A',
        'difficulty' => 'easy',
        'status' => 'approved',
        'source' => 'manual',
    ]);

    $graded = $this->service->grade($this->registration, [
        $this->mcq->id => '4',
        $foreignQuestion->id => 'A',
    ]);

    expect((float) $graded->score)->toBe(1.0);
});

it('does not re-grade an already-graded registration', function () {
    $this->service->grade($this->registration, [
        $this->mcq->id => '4',
        $this->numerical->id => 3.15,
    ]);

    $regraded = $this->service->grade($this->registration->fresh(), [
        $this->mcq->id => '3',
        $this->numerical->id => 999,
    ]);

    expect((float) $regraded->score)->toBe(2.0);
});
