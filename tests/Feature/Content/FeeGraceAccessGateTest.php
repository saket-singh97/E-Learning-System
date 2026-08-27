<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Services\LessonAccess;
use App\Domains\Fees\Models\FeeInvoice;
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

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');

    $this->access = app(LessonAccess::class);

    // Course + batch + a fresh Enrolment + a lesson the student can stream
    // once enrolled — an overdue FeeInvoice tied to $enrolment is what each
    // test then adds (or not).
    $this->makeCourseLesson = function (string $slug): array {
        $course = Course::create(['title' => 'Course '.$slug, 'slug' => $slug, 'status' => 'published']);
        $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);
        $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Lesson', 'type' => 'text', 'position' => 0, 'text_body' => 'Hello']);
        $batch = Batch::create([
            'course_id' => $course->id,
            'title' => 'Batch '.$slug,
            'slug' => 'batch-'.$slug,
            'starts_on' => now()->subMonth()->toDateString(),
            'ends_on' => now()->addMonths(3)->toDateString(),
            'self_enrol_mode' => 'manual_only',
            'status' => 'published',
        ]);
        $enrolment = Enrolment::create([
            'batch_id' => $batch->id,
            'user_id' => $this->student->id,
            'status' => 'active',
            'source' => 'manual_add',
            'requested_at' => now(),
        ]);

        return [$lesson, $batch, $enrolment];
    };

    $this->makeOverdueInvoice = function (Batch $batch, Enrolment $enrolment, string $dueDate): FeeInvoice {
        return FeeInvoice::create([
            'batch_id' => $batch->id,
            'enrolment_id' => $enrolment->id,
            'user_id' => $this->student->id,
            'sequence_number' => 1,
            'invoice_number' => 'INV-'.uniqid(),
            'description' => 'Instalment',
            'amount' => 1000,
            'gst_amount' => 180,
            'total_amount' => 1180,
            'due_date' => $dueDate,
            'status' => 'pending',
        ]);
    };
});

it('blocks streaming once an unpaid instalment is past the tenants grace period', function () {
    $this->tenant->update(['fee_grace_period_days' => 7]);
    [$lesson, $batch, $enrolment] = ($this->makeCourseLesson)('a');
    ($this->makeOverdueInvoice)($batch, $enrolment, now()->subDays(10)->toDateString());

    expect($this->access->canStream($lesson, $this->student))->toBeFalse();
});

it('still allows streaming while the overdue instalment is within the grace period', function () {
    $this->tenant->update(['fee_grace_period_days' => 7]);
    [$lesson, $batch, $enrolment] = ($this->makeCourseLesson)('a');
    ($this->makeOverdueInvoice)($batch, $enrolment, now()->subDays(2)->toDateString());

    expect($this->access->canStream($lesson, $this->student))->toBeTrue();
});

it('never blocks streaming when the tenant has not configured a grace period', function () {
    [$lesson, $batch, $enrolment] = ($this->makeCourseLesson)('a');
    ($this->makeOverdueInvoice)($batch, $enrolment, now()->subDays(30)->toDateString());

    expect($this->access->canStream($lesson, $this->student))->toBeTrue();
});

it('blocks the overdue course while leaving a different, current course untouched', function () {
    $this->tenant->update(['fee_grace_period_days' => 7]);
    [$overdueLesson, $overdueBatch, $overdueEnrolment] = ($this->makeCourseLesson)('overdue');
    [$currentLesson] = ($this->makeCourseLesson)('current');
    ($this->makeOverdueInvoice)($overdueBatch, $overdueEnrolment, now()->subDays(10)->toDateString());

    expect($this->access->canStream($overdueLesson, $this->student))->toBeFalse()
        ->and($this->access->canStream($currentLesson, $this->student))->toBeTrue();
});
