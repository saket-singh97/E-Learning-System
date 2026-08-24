<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Content\Models\AttendanceRecord;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Filament\Admin\Pages\AtRiskStudents;
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

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin->assignRole('institute_admin');

    $this->actingAs($this->admin)
        ->get(AtRiskStudents::getUrl(['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();
});

function atRiskSession(Tenant $tenant): BatchSession
{
    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $batch = Batch::create(['course_id' => $course->id, 'title' => 'Batch', 'slug' => 'batch-'.uniqid(), 'starts_on' => now()->toDateString(), 'ends_on' => now()->addMonth()->toDateString(), 'self_enrol_mode' => 'manual_only', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Live', 'type' => 'live_session', 'position' => 0]);

    return BatchSession::create(['batch_id' => $batch->id, 'lesson_id' => $lesson->id, 'starts_at' => now(), 'duration_minutes' => 60, 'position' => 0]);
}

it('lists a below-threshold student but not a healthy or no-data one', function () {
    $session = atRiskSession($this->tenant);

    $atRisk = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $atRisk->assignRole('student');
    AttendanceRecord::create(['attendable_type' => BatchSession::class, 'attendable_id' => $session->id, 'user_id' => $atRisk->id, 'status' => 'absent', 'source' => 'manual', 'recorded_at' => now()]);

    $healthy = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $healthy->assignRole('student');
    AttendanceRecord::create(['attendable_type' => BatchSession::class, 'attendable_id' => $session->id, 'user_id' => $healthy->id, 'status' => 'present', 'source' => 'manual', 'recorded_at' => now()]);

    $noData = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $noData->assignRole('student');

    Livewire::test(AtRiskStudents::class)
        ->assertCanSeeTableRecords([$atRisk])
        ->assertCanNotSeeTableRecords([$healthy, $noData]);
});

it('excludes an at-risk student from a different tenant', function () {
    $session = atRiskSession($this->tenant);
    $atRisk = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $atRisk->assignRole('student');
    AttendanceRecord::create(['attendable_type' => BatchSession::class, 'attendable_id' => $session->id, 'user_id' => $atRisk->id, 'status' => 'absent', 'source' => 'manual', 'recorded_at' => now()]);

    $otherTenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($otherTenant);
    $otherSession = atRiskSession($otherTenant);
    $otherAtRisk = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherAtRisk->assignRole('student');
    AttendanceRecord::create(['attendable_type' => BatchSession::class, 'attendable_id' => $otherSession->id, 'user_id' => $otherAtRisk->id, 'status' => 'absent', 'source' => 'manual', 'recorded_at' => now()]);
    app(CurrentTenant::class)->set($this->tenant);

    Livewire::test(AtRiskStudents::class)
        ->assertCanSeeTableRecords([$atRisk])
        ->assertCanNotSeeTableRecords([$otherAtRisk]);
});
