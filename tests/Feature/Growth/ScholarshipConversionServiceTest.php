<?php

use App\Domains\Assessments\Models\Quiz;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Growth\Models\ScholarshipTest;
use App\Domains\Growth\Models\ScholarshipTestRegistration;
use App\Domains\Growth\Services\ScholarshipConversionService;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);
    $this->service = app(ScholarshipConversionService::class);

    $section = CourseSection::create(['course_id' => Course::create(['title' => 'Bootstrap', 'slug' => 'bootstrap'])->id, 'title' => 'S', 'position' => 0]);
    $this->quiz = Quiz::create([
        'lesson_id' => Lesson::create(['course_section_id' => $section->id, 'title' => 'L', 'type' => 'quiz', 'position' => 0])->id,
        'title' => 'Q',
        'blueprint' => [],
        'time_limit_minutes' => 60,
        'max_attempts' => 1,
    ]);
});

function scholarshipConversionEnrol(Course $course, string $email, Tenant $tenant): void
{
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'email' => $email]);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    Enrolment::create([
        'batch_id' => $batch->id,
        'user_id' => $user->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);
}

it('reports converted once a real enrolment exists for a course-scoped test', function () {
    $course = Course::create(['title' => 'Physics', 'slug' => 'physics', 'status' => 'published']);
    $test = ScholarshipTest::create(['course_id' => $course->id, 'title' => 'T', 'quiz_id' => $this->quiz->id, 'opens_at' => now(), 'closes_at' => now()->addWeek()]);
    $registration = ScholarshipTestRegistration::create(['scholarship_test_id' => $test->id, 'name' => 'Asha', 'email' => 'asha@example.com']);

    expect($this->service->hasConverted($registration))->toBeFalse();

    scholarshipConversionEnrol($course, 'asha@example.com', $this->tenant);

    expect($this->service->hasConverted($registration))->toBeTrue();
});

it('does not match a same-email registration when the enrolment is under a different course', function () {
    $testCourse = Course::create(['title' => 'Physics', 'slug' => 'physics', 'status' => 'published']);
    $enrolledCourse = Course::create(['title' => 'Chemistry', 'slug' => 'chemistry', 'status' => 'published']);
    $test = ScholarshipTest::create(['course_id' => $testCourse->id, 'title' => 'T', 'quiz_id' => $this->quiz->id, 'opens_at' => now(), 'closes_at' => now()->addWeek()]);
    $registration = ScholarshipTestRegistration::create(['scholarship_test_id' => $test->id, 'name' => 'Asha', 'email' => 'asha@example.com']);

    scholarshipConversionEnrol($enrolledCourse, 'asha@example.com', $this->tenant);

    expect($this->service->hasConverted($registration))->toBeFalse();
});

it('matches any enrolment by email+tenant when the test has no linked course', function () {
    $enrolledCourse = Course::create(['title' => 'Chemistry', 'slug' => 'chemistry', 'status' => 'published']);
    $test = ScholarshipTest::create(['title' => 'T', 'quiz_id' => $this->quiz->id, 'opens_at' => now(), 'closes_at' => now()->addWeek()]);
    $registration = ScholarshipTestRegistration::create(['scholarship_test_id' => $test->id, 'name' => 'Asha', 'email' => 'asha@example.com']);

    scholarshipConversionEnrol($enrolledCourse, 'asha@example.com', $this->tenant);

    expect($this->service->hasConverted($registration))->toBeTrue();
});
