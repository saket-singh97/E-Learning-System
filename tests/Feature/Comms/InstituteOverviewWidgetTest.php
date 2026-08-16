<?php

use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\AttendanceRecord;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Tenancy\Models\Tenant;
use App\Filament\Admin\Widgets\InstituteOverviewWidget;
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
        ->get('http://physics.lms.test/admin')
        ->assertOk();
});

function widgetBatch(Tenant $tenant): Batch
{
    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);

    return Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
}

it('counts only active enrolments in the current tenant', function () {
    $batch = widgetBatch($this->tenant);

    Enrolment::create(['batch_id' => $batch->id, 'user_id' => User::factory()->create(['tenant_id' => $this->tenant->id])->id, 'status' => 'active', 'source' => 'manual_add', 'requested_at' => now()]);
    Enrolment::create(['batch_id' => $batch->id, 'user_id' => User::factory()->create(['tenant_id' => $this->tenant->id])->id, 'status' => 'active', 'source' => 'manual_add', 'requested_at' => now()]);
    Enrolment::create(['batch_id' => $batch->id, 'user_id' => User::factory()->create(['tenant_id' => $this->tenant->id])->id, 'status' => 'waitlisted', 'source' => 'manual_add', 'requested_at' => now()]);

    $otherTenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($otherTenant);
    $otherBatch = widgetBatch($otherTenant);
    Enrolment::create(['batch_id' => $otherBatch->id, 'user_id' => User::factory()->create(['tenant_id' => $otherTenant->id])->id, 'status' => 'active', 'source' => 'manual_add', 'requested_at' => now()]);
    app(CurrentTenant::class)->set($this->tenant);

    Livewire::test(InstituteOverviewWidget::class)
        ->assertSeeInOrder(['Active enrolments', '2']);
});

it('sums only paid invoices toward revenue', function () {
    $batch = widgetBatch($this->tenant);
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $enrolment = Enrolment::create(['batch_id' => $batch->id, 'user_id' => $student->id, 'status' => 'active', 'source' => 'manual_add', 'requested_at' => now()]);

    FeeInvoice::create(['batch_id' => $batch->id, 'enrolment_id' => $enrolment->id, 'user_id' => $student->id, 'sequence_number' => 1, 'invoice_number' => 'INV-1', 'description' => 'Fee', 'amount' => 100, 'gst_amount' => 18, 'total_amount' => 118, 'due_date' => now()->toDateString(), 'status' => 'paid', 'paid_at' => now()]);
    FeeInvoice::create(['batch_id' => $batch->id, 'enrolment_id' => $enrolment->id, 'user_id' => $student->id, 'sequence_number' => 2, 'invoice_number' => 'INV-2', 'description' => 'Fee', 'amount' => 200, 'gst_amount' => 36, 'total_amount' => 236, 'due_date' => now()->toDateString(), 'status' => 'paid', 'paid_at' => now()]);
    FeeInvoice::create(['batch_id' => $batch->id, 'enrolment_id' => $enrolment->id, 'user_id' => $student->id, 'sequence_number' => 3, 'invoice_number' => 'INV-3', 'description' => 'Fee', 'amount' => 500, 'gst_amount' => 90, 'total_amount' => 590, 'due_date' => now()->toDateString(), 'status' => 'pending']);

    Livewire::test(InstituteOverviewWidget::class)
        ->assertSee('354.00');
});

it('counts a student below the attendance threshold as at-risk, but not one above it or one with no data yet', function () {
    $batch = widgetBatch($this->tenant);
    $section = CourseSection::create(['course_id' => $batch->course_id, 'title' => 'Section', 'position' => 0]);
    $liveLesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Live', 'type' => 'live_session', 'position' => 0]);
    $session = BatchSession::create(['batch_id' => $batch->id, 'lesson_id' => $liveLesson->id, 'starts_at' => now(), 'duration_minutes' => 60, 'position' => 0]);

    $atRiskStudent = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $atRiskStudent->assignRole('student');
    AttendanceRecord::create(['attendable_type' => BatchSession::class, 'attendable_id' => $session->id, 'user_id' => $atRiskStudent->id, 'status' => 'absent', 'source' => 'manual', 'recorded_at' => now()]);

    $healthyStudent = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $healthyStudent->assignRole('student');
    AttendanceRecord::create(['attendable_type' => BatchSession::class, 'attendable_id' => $session->id, 'user_id' => $healthyStudent->id, 'status' => 'present', 'source' => 'manual', 'recorded_at' => now()]);

    $noDataStudent = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $noDataStudent->assignRole('student');

    Livewire::test(InstituteOverviewWidget::class)
        ->assertSeeInOrder(['At-risk students', '1']);
});

it('counts a student below the gradebook threshold as at-risk on grades, but not one passing or with no data yet', function () {
    $batch = widgetBatch($this->tenant);
    $section = CourseSection::create(['course_id' => $batch->course_id, 'title' => 'Section', 'position' => 0]);
    $quizLesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Quiz', 'type' => 'quiz', 'position' => 0]);
    $quiz = Quiz::create(['lesson_id' => $quizLesson->id, 'title' => 'Checkpoint', 'blueprint' => [], 'time_limit_minutes' => 20, 'max_attempts' => 3]);

    $failingStudent = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $failingStudent->assignRole('student');
    Enrolment::create(['batch_id' => $batch->id, 'user_id' => $failingStudent->id, 'status' => 'active', 'source' => 'manual_add', 'requested_at' => now()]);
    QuizAttempt::create([
        'quiz_id' => $quiz->id, 'user_id' => $failingStudent->id, 'question_ids' => [],
        'status' => 'submitted', 'started_at' => now(), 'expires_at' => now()->addMinutes(20),
        'submitted_at' => now(), 'score' => 2, 'max_score' => 10,
    ]);

    $passingStudent = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $passingStudent->assignRole('student');
    Enrolment::create(['batch_id' => $batch->id, 'user_id' => $passingStudent->id, 'status' => 'active', 'source' => 'manual_add', 'requested_at' => now()]);
    QuizAttempt::create([
        'quiz_id' => $quiz->id, 'user_id' => $passingStudent->id, 'question_ids' => [],
        'status' => 'submitted', 'started_at' => now(), 'expires_at' => now()->addMinutes(20),
        'submitted_at' => now(), 'score' => 9, 'max_score' => 10,
    ]);

    $noDataStudent = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $noDataStudent->assignRole('student');
    Enrolment::create(['batch_id' => $batch->id, 'user_id' => $noDataStudent->id, 'status' => 'active', 'source' => 'manual_add', 'requested_at' => now()]);

    Livewire::test(InstituteOverviewWidget::class)
        ->assertSeeInOrder(['At-risk on grades', '1']);
});
