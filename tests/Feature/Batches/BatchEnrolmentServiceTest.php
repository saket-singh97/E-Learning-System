<?php

use App\Domains\Batches\Events\EnrolmentAwaitingPayment;
use App\Domains\Batches\Events\SeatReleased;
use App\Domains\Batches\Events\StudentEnrolled;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Batches\Services\BatchEnrolmentService;
use App\Domains\Content\Models\Course;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $this->service = app(BatchEnrolmentService::class);

    $this->batch = fn (array $overrides = []) => Batch::create(array_merge([
        'course_id' => $this->course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-batch-'.uniqid(),
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'self_enrol_direct',
        'status' => 'published',
    ], $overrides));

    $this->student = function (): User {
        $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $student->assignRole('student');

        return $student;
    };
});

it('lands active on a self_enrol_direct batch with a free seat', function () {
    $batch = ($this->batch)(['seat_limit' => 10]);
    $student = ($this->student)();

    $enrolment = $this->service->enrol($student, $batch);

    expect($enrolment->status)->toBe('active')
        ->and($enrolment->source)->toBe('self_enrol_direct');
});

it('waitlists once the seat limit is reached on a self_enrol_direct batch', function () {
    $batch = ($this->batch)(['seat_limit' => 1]);

    $first = $this->service->enrol(($this->student)(), $batch);
    $second = $this->service->enrol(($this->student)(), $batch);

    expect($first->status)->toBe('active')
        ->and($second->status)->toBe('waitlisted');
});

it('creates a pending_approval enrolment on a self_enrol_approval batch, expiring in 7 days', function () {
    $batch = ($this->batch)(['self_enrol_mode' => 'self_enrol_approval']);
    $student = ($this->student)();

    $enrolment = $this->service->enrol($student, $batch);

    expect($enrolment->status)->toBe('pending_approval')
        ->and($enrolment->source)->toBe('self_enrol_approval')
        ->and((int) $enrolment->requested_at->diffInDays($enrolment->expires_at))->toBe(7);
});

it('refuses self-service enrol() on a manual_only batch', function () {
    $batch = ($this->batch)(['self_enrol_mode' => 'manual_only']);
    $student = ($this->student)();

    expect(fn () => $this->service->enrol($student, $batch))->toThrow(RuntimeException::class);
});

it('adds a student manually as active regardless of self_enrol_mode', function () {
    $batch = ($this->batch)(['self_enrol_mode' => 'manual_only']);
    $student = ($this->student)();
    $staff = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $staff->assignRole('institute_admin');

    $enrolment = $this->service->addManually($batch, $student, $staff);

    expect($enrolment->status)->toBe('active')
        ->and($enrolment->source)->toBe('manual_add')
        ->and($enrolment->decided_by)->toBe($staff->id);
});

it('returns the existing enrolment instead of a database error on a duplicate attempt', function () {
    $batch = ($this->batch)(['seat_limit' => 10]);
    $student = ($this->student)();

    $first = $this->service->enrol($student, $batch);
    $second = $this->service->enrol($student, $batch);

    expect($second->id)->toBe($first->id)
        ->and(Enrolment::where('batch_id', $batch->id)->where('user_id', $student->id)->count())->toBe(1);
});

it('reports seats remaining as null for an unlimited batch and decreasing for a capped one', function () {
    $unlimited = ($this->batch)(['seat_limit' => null]);
    expect($this->service->seatsRemaining($unlimited))->toBeNull();

    $capped = ($this->batch)(['seat_limit' => 2]);
    expect($this->service->seatsRemaining($capped))->toBe(2);

    $this->service->enrol(($this->student)(), $capped);
    expect($this->service->seatsRemaining($capped))->toBe(1);
});

it('canEnrol is false for a draft batch or a batch whose course is not published', function () {
    $draftBatch = ($this->batch)(['status' => 'draft']);
    expect($this->service->canEnrol(($this->student)(), $draftBatch))->toBeFalse();

    $draftCourse = Course::create(['title' => 'Draft Course', 'slug' => 'draft-course', 'status' => 'draft']);
    $batch = ($this->batch)(['course_id' => $draftCourse->id]);
    expect($this->service->canEnrol(($this->student)(), $batch))->toBeFalse();
});

it('refuses enrol() on a draft batch even though canEnrol() is what the caller is supposed to check first', function () {
    // enrol() is the mutator BatchCatalog::join() calls directly (a
    // Livewire method with no role gate) — canEnrol() being false must not
    // be merely advisory, or a forged batchId bypasses the catalog's
    // published-only display query entirely.
    $draftBatch = ($this->batch)(['status' => 'draft']);
    $student = ($this->student)();

    expect(fn () => $this->service->enrol($student, $draftBatch))->toThrow(RuntimeException::class);
    expect(Enrolment::where('batch_id', $draftBatch->id)->count())->toBe(0);
});

it('refuses enrol() when the batch is published but its course is not', function () {
    $draftCourse = Course::create(['title' => 'Draft Course', 'slug' => 'draft-course-enrol', 'status' => 'draft']);
    $batch = ($this->batch)(['course_id' => $draftCourse->id]);
    $student = ($this->student)();

    expect(fn () => $this->service->enrol($student, $batch))->toThrow(RuntimeException::class);
    expect(Enrolment::where('batch_id', $batch->id)->count())->toBe(0);
});

it('canEnrol is false once the student already holds an enrolment for the batch', function () {
    $batch = ($this->batch)(['seat_limit' => 10]);
    $student = ($this->student)();

    expect($this->service->canEnrol($student, $batch))->toBeTrue();

    $this->service->enrol($student, $batch);

    expect($this->service->canEnrol($student, $batch))->toBeFalse();
});

it('cancelling an active enrolment promotes the oldest waitlisted row', function () {
    $batch = ($this->batch)(['seat_limit' => 1]);

    $active = $this->service->enrol(($this->student)(), $batch);
    $waitlisted = $this->service->enrol(($this->student)(), $batch);

    $this->service->cancel($active);

    expect($active->fresh()->status)->toBe('cancelled')
        ->and($waitlisted->fresh()->status)->toBe('active');
});

it('promotes strictly FIFO across 3+ waitlisted students', function () {
    $batch = ($this->batch)(['seat_limit' => 1]);

    $active = $this->service->enrol(($this->student)(), $batch);

    $this->travelTo(now()->addSecond());
    $first = $this->service->enrol(($this->student)(), $batch);

    $this->travelTo(now()->addSecond());
    $second = $this->service->enrol(($this->student)(), $batch);

    $this->travelTo(now()->addSecond());
    $third = $this->service->enrol(($this->student)(), $batch);

    $this->service->cancel($active);
    expect($first->fresh()->status)->toBe('active')
        ->and($second->fresh()->status)->toBe('waitlisted')
        ->and($third->fresh()->status)->toBe('waitlisted');

    $this->service->cancel($first->fresh());
    expect($second->fresh()->status)->toBe('active')
        ->and($third->fresh()->status)->toBe('waitlisted');
});

it('promotes the earlier-inserted waitlisted row when created_at ties (Postgres timestamp(0) precision)', function () {
    // enrolments.created_at is whole-second precision in this app's
    // Postgres schema (no explicit column precision set) — two students
    // joining within the same wall-clock second, a real scenario the "ten
    // students" demo can hit, tie on created_at alone. id (strictly
    // increasing at insert) is the deterministic secondary key that
    // actually preserves arrival order for that tie.
    $batch = ($this->batch)(['seat_limit' => 1]);

    $active = $this->service->enrol(($this->student)(), $batch);
    $first = $this->service->enrol(($this->student)(), $batch);
    $second = $this->service->enrol(($this->student)(), $batch);

    $tiedAt = $first->created_at;
    $first->forceFill(['created_at' => $tiedAt])->save();
    $second->forceFill(['created_at' => $tiedAt])->save();

    $this->service->cancel($active);

    expect($first->fresh()->status)->toBe('active')
        ->and($second->fresh()->status)->toBe('waitlisted');
});

it('cancelling when nobody is waitlisted is a no-op, not an error', function () {
    $batch = ($this->batch)(['seat_limit' => 10]);
    $active = $this->service->enrol(($this->student)(), $batch);

    $result = $this->service->cancel($active);

    expect($result->status)->toBe('cancelled');
});

it('cancelling a non-active enrolment does not promote anyone', function () {
    $batch = ($this->batch)(['seat_limit' => 1]);

    $this->service->enrol(($this->student)(), $batch);
    $waitlisted = $this->service->enrol(($this->student)(), $batch);

    $this->service->cancel($waitlisted);

    expect($waitlisted->fresh()->status)->toBe('cancelled');
});

it('approves a pending_approval enrolment to active when a seat is open', function () {
    $batch = ($this->batch)(['self_enrol_mode' => 'self_enrol_approval', 'seat_limit' => 1]);
    $student = ($this->student)();
    $staff = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $staff->assignRole('institute_admin');

    $enrolment = $this->service->enrol($student, $batch);
    $approved = $this->service->approve($enrolment, $staff);

    expect($approved->status)->toBe('active')
        ->and($approved->decided_by)->toBe($staff->id);
});

it('refuses to approve a pending_approval enrolment once the batch is full', function () {
    $batch = ($this->batch)(['self_enrol_mode' => 'self_enrol_approval', 'seat_limit' => 1]);
    $staff = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $staff->assignRole('institute_admin');

    $firstRequest = $this->service->enrol(($this->student)(), $batch);
    $secondRequest = $this->service->enrol(($this->student)(), $batch);

    $this->service->approve($firstRequest, $staff);

    expect(fn () => $this->service->approve($secondRequest, $staff))
        ->toThrow(\App\Exceptions\BatchFullException::class);
    expect($secondRequest->fresh()->status)->toBe('pending_approval');
});

it('lands pending_payment on a paid self_enrol_direct batch, not active', function () {
    $batch = ($this->batch)(['is_paid' => true, 'price' => 500]);
    $student = ($this->student)();

    $enrolment = $this->service->enrol($student, $batch);

    expect($enrolment->status)->toBe('pending_payment')
        ->and($enrolment->source)->toBe('self_enrol_direct');
});

it('lands pending_payment on a paid self_enrol_approval batch too, recording the mode for later finalization', function () {
    $batch = ($this->batch)(['is_paid' => true, 'price' => 500, 'self_enrol_mode' => 'self_enrol_approval']);
    $student = ($this->student)();

    $enrolment = $this->service->enrol($student, $batch);

    expect($enrolment->status)->toBe('pending_payment')
        ->and($enrolment->source)->toBe('self_enrol_approval');
});

it('still refuses self-service enrol() on a paid manual_only batch', function () {
    $batch = ($this->batch)(['is_paid' => true, 'price' => 500, 'self_enrol_mode' => 'manual_only']);
    $student = ($this->student)();

    expect(fn () => $this->service->enrol($student, $batch))->toThrow(RuntimeException::class);
    expect(Enrolment::where('batch_id', $batch->id)->count())->toBe(0);
});

it('creates a FeeInvoice for a pending_payment enrolment via the EnrolmentAwaitingPayment listener', function () {
    $batch = ($this->batch)(['is_paid' => true, 'price' => 500]);
    $student = ($this->student)();

    $enrolment = $this->service->enrol($student, $batch);

    $invoice = FeeInvoice::where('enrolment_id', $enrolment->id)->sole();

    expect((float) $invoice->amount)->toBe(500.0)
        ->and($invoice->status)->toBe('pending');
});

it('fires EnrolmentAwaitingPayment exactly once for a paid batch enrol()', function () {
    Event::fake([EnrolmentAwaitingPayment::class]);

    $batch = ($this->batch)(['is_paid' => true, 'price' => 500]);
    $this->service->enrol(($this->student)(), $batch);

    Event::assertDispatchedTimes(EnrolmentAwaitingPayment::class, 1);
});

it('does not create a FeeInvoice for a free batch', function () {
    $batch = ($this->batch)(['seat_limit' => 10]);
    $this->service->enrol(($this->student)(), $batch);

    expect(FeeInvoice::count())->toBe(0);
});

it('finalizes a pending_payment self_enrol_direct enrolment to active when a seat is open', function () {
    $batch = ($this->batch)(['is_paid' => true, 'price' => 500, 'seat_limit' => 10]);
    $enrolment = $this->service->enrol(($this->student)(), $batch);

    $finalized = $this->service->finalizePendingPayment($enrolment);

    expect($finalized->status)->toBe('active');
});

it('finalizes a pending_payment self_enrol_direct enrolment to waitlisted once the batch is full', function () {
    $batch = ($this->batch)(['is_paid' => true, 'price' => 500, 'seat_limit' => 1]);
    $this->service->enrol(($this->student)(), $batch)->update(['status' => 'active']);

    $second = $this->service->enrol(($this->student)(), $batch);
    $finalized = $this->service->finalizePendingPayment($second);

    expect($finalized->status)->toBe('waitlisted');
});

it('finalizes a pending_payment self_enrol_approval enrolment to pending_approval, expiring in 7 days', function () {
    $batch = ($this->batch)(['is_paid' => true, 'price' => 500, 'self_enrol_mode' => 'self_enrol_approval']);
    $enrolment = $this->service->enrol(($this->student)(), $batch);

    $finalized = $this->service->finalizePendingPayment($enrolment);

    // Compared by calendar date, not diffInDays() against a fresh now() —
    // expires_at round-trips through Eloquent's datetime cast (a string,
    // no microseconds) on write, so a fresh in-memory now() sits a
    // fraction of a second ahead of it; diffInDays() then floors an
    // almost-but-not-quite-7.0 span down to 6. Day-granularity is what
    // "expiring in 7 days" actually means here anyway.
    expect($finalized->status)->toBe('pending_approval')
        ->and($finalized->expires_at->toDateString())->toBe(now()->addDays(7)->toDateString());
});

it('finalizePendingPayment is a no-op for an enrolment that is not pending_payment', function () {
    $batch = ($this->batch)(['seat_limit' => 10]);
    $enrolment = $this->service->enrol(($this->student)(), $batch);

    $result = $this->service->finalizePendingPayment($enrolment);

    expect($result->status)->toBe('active')
        ->and($result->id)->toBe($enrolment->id);
});

it('fires StudentEnrolled exactly once per promotion', function () {
    Event::fake([StudentEnrolled::class, SeatReleased::class]);

    $batch = ($this->batch)(['seat_limit' => 1]);
    $active = $this->service->enrol(($this->student)(), $batch);
    $this->service->enrol(($this->student)(), $batch);

    Event::assertDispatchedTimes(StudentEnrolled::class, 1);

    $this->service->cancel($active);

    Event::assertDispatchedTimes(StudentEnrolled::class, 2);
    Event::assertDispatchedTimes(SeatReleased::class, 1);
});
