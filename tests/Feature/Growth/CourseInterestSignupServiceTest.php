<?php

use App\Domains\Content\Models\Course;
use App\Domains\Growth\Models\CourseInterestSignup;
use App\Domains\Growth\Services\CourseInterestSignupService;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->course = Course::create(['title' => 'Upcoming Course', 'slug' => 'upcoming-course', 'status' => 'draft']);
    $this->service = app(CourseInterestSignupService::class);
});

it('persists a new signup correctly', function () {
    $signup = $this->service->signup($this->course, 'Asha', 'asha@example.com', '9999999999');

    expect($signup->course_id)->toBe($this->course->id)
        ->and($signup->name)->toBe('Asha')
        ->and($signup->email)->toBe('asha@example.com')
        ->and($signup->phone)->toBe('9999999999')
        ->and(CourseInterestSignup::count())->toBe(1);
});

it('updates the existing row for a duplicate email on the same course instead of creating a second one', function () {
    $this->service->signup($this->course, 'Asha', 'asha@example.com', '1111111111');
    $this->service->signup($this->course, 'Asha Verma', 'asha@example.com', '2222222222');

    expect(CourseInterestSignup::count())->toBe(1);

    $signup = CourseInterestSignup::sole();
    expect($signup->name)->toBe('Asha Verma')
        ->and($signup->phone)->toBe('2222222222');
});

it('allows the same email to sign up for two different courses', function () {
    $otherCourse = Course::create(['title' => 'Another Course', 'slug' => 'another-course', 'status' => 'draft']);

    $this->service->signup($this->course, 'Asha', 'asha@example.com', null);
    $this->service->signup($otherCourse, 'Asha', 'asha@example.com', null);

    expect(CourseInterestSignup::count())->toBe(2);
});
