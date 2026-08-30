<?php

use App\Domains\Assessments\Models\Quiz;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Growth\Models\ScholarshipTest;
use App\Domains\Growth\Models\ScholarshipTestRegistration;
use App\Domains\Growth\Services\ScholarshipTestRegistrationService;
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

    $this->service = app(ScholarshipTestRegistrationService::class);
});

it('persists a new registration correctly', function () {
    $registration = $this->service->register($this->test, 'Asha', 'asha@example.com', '9999999999');

    expect($registration->scholarship_test_id)->toBe($this->test->id)
        ->and($registration->name)->toBe('Asha')
        ->and($registration->email)->toBe('asha@example.com')
        ->and($registration->phone)->toBe('9999999999')
        ->and(ScholarshipTestRegistration::count())->toBe(1);
});

it('updates the existing row for a duplicate email on the same test instead of creating a second one', function () {
    $this->service->register($this->test, 'Asha', 'asha@example.com', '1111111111');
    $this->service->register($this->test, 'Asha Verma', 'asha@example.com', '2222222222');

    expect(ScholarshipTestRegistration::count())->toBe(1);

    $registration = ScholarshipTestRegistration::sole();
    expect($registration->name)->toBe('Asha Verma')
        ->and($registration->phone)->toBe('2222222222');
});

it('allows the same email to register for two different scholarship tests', function () {
    $otherQuiz = Quiz::create([
        'lesson_id' => Lesson::create(['course_section_id' => CourseSection::create(['course_id' => Course::create(['title' => 'Chem', 'slug' => 'chem'])->id, 'title' => 'Section', 'position' => 0])->id, 'title' => 'Quiz Lesson', 'type' => 'quiz', 'position' => 0])->id,
        'title' => 'Other Quiz',
        'blueprint' => [],
        'time_limit_minutes' => 60,
        'max_attempts' => 1,
    ]);
    $otherTest = ScholarshipTest::create([
        'title' => 'Regional Scholarship Test',
        'quiz_id' => $otherQuiz->id,
        'opens_at' => now()->subDay(),
        'closes_at' => now()->addWeek(),
    ]);

    $this->service->register($this->test, 'Asha', 'asha@example.com', null);
    $this->service->register($otherTest, 'Asha', 'asha@example.com', null);

    expect(ScholarshipTestRegistration::count())->toBe(2);
});
