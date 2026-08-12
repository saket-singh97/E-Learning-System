<?php

use App\Domains\Assessments\Models\Quiz;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $this->section = CourseSection::create(['course_id' => $this->course->id, 'title' => 'Kinematics', 'position' => 0]);
    $this->lesson = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'Checkpoint', 'type' => 'quiz', 'position' => 0]);

    $this->quiz = Quiz::create([
        'lesson_id' => $this->lesson->id,
        'title' => 'Kinematics Checkpoint',
        'blueprint' => [['count' => 1]],
        'time_limit_minutes' => 20,
        'max_attempts' => 2,
        'status' => 'published',
    ]);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');
});

it('serves a published quiz on a published course to a student', function () {
    $this->actingAs($this->student)
        ->get("http://physics.lms.test/quizzes/{$this->quiz->id}")
        ->assertOk()
        ->assertSee('Kinematics Checkpoint');
});

it('404s a draft quiz for a student instead of leaking its title', function () {
    $this->quiz->update(['status' => 'draft', 'title' => 'SECRET DRAFT QUIZ']);

    $this->actingAs($this->student)
        ->get("http://physics.lms.test/quizzes/{$this->quiz->id}")
        ->assertNotFound()
        ->assertDontSee('SECRET DRAFT QUIZ');
});

it('404s a quiz whose course is a draft, even if the quiz itself is published', function () {
    $this->course->update(['status' => 'draft']);

    $this->actingAs($this->student)
        ->get("http://physics.lms.test/quizzes/{$this->quiz->id}")
        ->assertNotFound();
});

it('403s a published quiz for a guest, without a 404 that would hide it exists', function () {
    $this->get("http://physics.lms.test/quizzes/{$this->quiz->id}")
        ->assertForbidden();
});

it('lets an instructor preview their own draft quiz', function () {
    $this->quiz->update(['status' => 'draft']);

    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');

    $this->actingAs($instructor)
        ->get("http://physics.lms.test/quizzes/{$this->quiz->id}")
        ->assertOk()
        ->assertSee('Kinematics Checkpoint');
});

it('404s a published quiz for a student authenticated as a different tenant', function () {
    // SESSION_DOMAIN is a shared parent domain, so the shared session
    // cookie keeps a different tenant's student authenticated here. Spatie
    // roles carry no tenant_id, so isVisibleTo()'s old published-course
    // check passed regardless of the viewer's own tenant.
    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    $otherStudent = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherStudent->assignRole('student');

    $this->actingAs($otherStudent)
        ->get("http://physics.lms.test/quizzes/{$this->quiz->id}")
        ->assertNotFound();
});

it('404s a draft quiz for a different tenants instructor despite the staff bypass', function () {
    $this->quiz->update(['status' => 'draft']);

    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    $otherInstructor = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherInstructor->assignRole('instructor');

    $this->actingAs($otherInstructor)
        ->get("http://physics.lms.test/quizzes/{$this->quiz->id}")
        ->assertNotFound();
});
