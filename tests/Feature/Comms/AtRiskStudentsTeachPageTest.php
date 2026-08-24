<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\AttendanceRecord;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Filament\Teach\Pages\AtRiskStudents;
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

    $this->actingAs($this->instructor)
        ->get(AtRiskStudents::getUrl(['subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();
});

function enrolAtRiskStudent(Tenant $tenant, ?User $instructor): array
{
    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $batch = Batch::create(['course_id' => $course->id, 'instructor_id' => $instructor?->id, 'title' => 'Batch', 'slug' => 'batch-'.uniqid(), 'starts_on' => now()->toDateString(), 'ends_on' => now()->addMonth()->toDateString(), 'self_enrol_mode' => 'manual_only', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Live', 'type' => 'live_session', 'position' => 0]);
    $session = BatchSession::create(['batch_id' => $batch->id, 'lesson_id' => $lesson->id, 'starts_at' => now(), 'duration_minutes' => 60, 'position' => 0]);

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');
    Enrolment::create(['batch_id' => $batch->id, 'user_id' => $student->id, 'status' => 'active', 'source' => 'manual_add', 'requested_at' => now()]);
    AttendanceRecord::create(['attendable_type' => BatchSession::class, 'attendable_id' => $session->id, 'user_id' => $student->id, 'status' => 'absent', 'source' => 'manual', 'recorded_at' => now()]);

    return [$student, $session];
}

it('lists an at-risk student from the instructor own batch', function () {
    [$student] = enrolAtRiskStudent($this->tenant, $this->instructor);

    Livewire::test(AtRiskStudents::class)
        ->assertCanSeeTableRecords([$student]);
});

it('excludes an at-risk student enrolled in another instructor own batch', function () {
    [$mine] = enrolAtRiskStudent($this->tenant, $this->instructor);

    $otherInstructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $otherInstructor->assignRole('instructor');
    [$notMine] = enrolAtRiskStudent($this->tenant, $otherInstructor);

    Livewire::test(AtRiskStudents::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$notMine]);
});
