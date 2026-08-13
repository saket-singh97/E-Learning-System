<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Services\BatchEnrolmentService;
use App\Domains\Content\Models\Course;
use App\Domains\Fees\Jobs\FlagOverdueInvoices;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->invoice = function (): FeeInvoice {
        $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101-'.uniqid(), 'status' => 'published']);
        $batch = Batch::create([
            'course_id' => $course->id,
            'title' => 'Batch',
            'slug' => 'batch-'.uniqid(),
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-12-01',
            'self_enrol_mode' => 'self_enrol_direct',
            'status' => 'published',
            'is_paid' => true,
            'price' => 1000,
        ]);
        $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $student->assignRole('student');
        $enrolment = app(BatchEnrolmentService::class)->enrol($student, $batch);

        return FeeInvoice::where('enrolment_id', $enrolment->id)->sole();
    };
});

it('flags a pending invoice past its due_date as overdue', function () {
    $invoice = ($this->invoice)();
    $invoice->update(['due_date' => now()->subDay()->toDateString()]);

    app(FlagOverdueInvoices::class)->handle();

    expect($invoice->fresh()->status)->toBe('overdue');
});

it('leaves a pending invoice due today untouched', function () {
    $invoice = ($this->invoice)();
    $invoice->update(['due_date' => now()->toDateString()]);

    app(FlagOverdueInvoices::class)->handle();

    expect($invoice->fresh()->status)->toBe('pending');
});

it('leaves a pending invoice not yet due untouched', function () {
    $invoice = ($this->invoice)();
    $invoice->update(['due_date' => now()->addDay()->toDateString()]);

    app(FlagOverdueInvoices::class)->handle();

    expect($invoice->fresh()->status)->toBe('pending');
});

it('does not touch a paid invoice even if its due_date has passed', function () {
    $invoice = ($this->invoice)();
    $invoice->update(['due_date' => now()->subDay()->toDateString(), 'status' => 'paid', 'paid_at' => now()]);

    app(FlagOverdueInvoices::class)->handle();

    expect($invoice->fresh()->status)->toBe('paid');
});

it('does not touch a cancelled invoice even if its due_date has passed', function () {
    $invoice = ($this->invoice)();
    $invoice->update(['due_date' => now()->subDay()->toDateString(), 'status' => 'cancelled']);

    app(FlagOverdueInvoices::class)->handle();

    expect($invoice->fresh()->status)->toBe('cancelled');
});

it('is a real queued job with retry configured', function () {
    // S9 B10: this was a plain class before — Schedule::job() ran it inline
    // (dispatchNow()), so $tries/backoff() would have been dead
    // configuration. Confirms the conversion actually took, not just that
    // the properties exist.
    expect(app(FlagOverdueInvoices::class))
        ->toBeInstanceOf(ShouldQueue::class);

    $job = new FlagOverdueInvoices;

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([60, 300, 900]);
});

it('sweeps every tenant even when CurrentTenant is left stale-bound to the other one, not explicitly cleared', function () {
    // Unlike "sweeps invoices across every tenant in one pass" below (which
    // explicitly clears CurrentTenant before calling handle()), this
    // exercises the exact new risk S9 B10 introduced by making this a real
    // queued job: a persistent Horizon worker can run any other
    // tenant-bound job right before this one in the same PHP process. The
    // second tenant is left bound (not cleared) all the way into handle()
    // below — proving handle()'s own clear() call, not test setup, is what
    // makes this safe.
    $first = ($this->invoice)();
    $first->update(['due_date' => now()->subDay()->toDateString()]);

    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    app(CurrentTenant::class)->set($otherTenant);
    $second = ($this->invoice)();
    $second->update(['due_date' => now()->subDay()->toDateString()]);

    expect(app(CurrentTenant::class)->id())->toBe($otherTenant->id);

    app(FlagOverdueInvoices::class)->handle();

    expect($first->fresh()->status)->toBe('overdue')
        ->and($second->fresh()->status)->toBe('overdue');
});

it('sweeps invoices across every tenant in one pass', function () {
    $first = ($this->invoice)();
    $first->update(['due_date' => now()->subDay()->toDateString()]);

    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    app(CurrentTenant::class)->set($otherTenant);
    $second = ($this->invoice)();
    $second->update(['due_date' => now()->subDay()->toDateString()]);

    // The real scheduled run has no CurrentTenant bound at all (see the
    // job's own note) — clearing it here, rather than switching back to
    // $this->tenant, is what actually exercises the cross-tenant sweep.
    // Leaving a specific tenant bound would silently apply
    // BelongsToTenant's global scope and only "sweep" that one tenant.
    app(CurrentTenant::class)->clear();

    app(FlagOverdueInvoices::class)->handle();

    expect($first->fresh()->status)->toBe('overdue')
        ->and($second->fresh()->status)->toBe('overdue');
});
