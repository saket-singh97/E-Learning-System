<?php

use App\Domains\Batches\Events\EnrolmentApprovalExpired;
use App\Domains\Batches\Jobs\ExpirePendingEnrolments;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Notifications\EnrolmentRequestExpired;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);

    $this->batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-batch-'.uniqid(),
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'self_enrol_approval',
        'status' => 'published',
    ]);

    $this->pendingEnrolment = function (string $expiresAt): Enrolment {
        $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $student->assignRole('student');

        return Enrolment::create([
            'batch_id' => $this->batch->id,
            'user_id' => $student->id,
            'status' => 'pending_approval',
            'source' => 'self_enrol_approval',
            'requested_at' => now()->subDays(7),
            'expires_at' => $expiresAt,
        ]);
    };
});

it('expires exactly the pending_approval rows past expires_at', function () {
    $expired = ($this->pendingEnrolment)(now()->subDay());
    $notYetExpired = ($this->pendingEnrolment)(now()->addDay());

    app(ExpirePendingEnrolments::class)->handle();

    expect($expired->fresh()->status)->toBe('expired')
        ->and($notYetExpired->fresh()->status)->toBe('pending_approval');
});

it('includes a row whose expires_at is exactly now', function () {
    $boundary = ($this->pendingEnrolment)(now());

    app(ExpirePendingEnrolments::class)->handle();

    expect($boundary->fresh()->status)->toBe('expired');
});

it('leaves non-pending statuses untouched even if expires_at has passed', function () {
    $active = ($this->pendingEnrolment)(now()->subDay());
    $active->update(['status' => 'active']);

    app(ExpirePendingEnrolments::class)->handle();

    expect($active->fresh()->status)->toBe('active');
});

it('fires EnrolmentApprovalExpired once per expired row', function () {
    Event::fake([EnrolmentApprovalExpired::class]);

    ($this->pendingEnrolment)(now()->subDay());
    ($this->pendingEnrolment)(now()->subHour());
    ($this->pendingEnrolment)(now()->addDay());

    app(ExpirePendingEnrolments::class)->handle();

    Event::assertDispatchedTimes(EnrolmentApprovalExpired::class, 2);
});

it('sends the requester an EnrolmentRequestExpired notification on expiry', function () {
    Notification::fake();

    $expired = ($this->pendingEnrolment)(now()->subDay());

    app(ExpirePendingEnrolments::class)->handle();

    Notification::assertSentTo($expired->user, EnrolmentRequestExpired::class);
});

it('sends no notification when nothing expires', function () {
    Notification::fake();

    ($this->pendingEnrolment)(now()->addDay());

    app(ExpirePendingEnrolments::class)->handle();

    Notification::assertNothingSent();
});

it('is a real queued job with retry configured', function () {
    // S9 B10: this was a plain class before — Schedule::job() ran it inline
    // (dispatchNow()), so $tries/backoff() would have been dead
    // configuration. Confirms the conversion actually took, not just that
    // the properties exist.
    expect(app(ExpirePendingEnrolments::class))
        ->toBeInstanceOf(ShouldQueue::class);

    $job = new ExpirePendingEnrolments;

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([60, 300, 900]);
});

it('sweeps every tenant even when CurrentTenant is left stale-bound to a different one at call time', function () {
    // Regression for the exact risk converting this job to a real queued
    // job introduced (S9 B10): a persistent Horizon worker can run any
    // other tenant-bound job right before this one in the same PHP
    // process (RebindsTenant sets CurrentTenant and never clears it), and
    // BelongsToTenant's global scope silently no-ops only when
    // CurrentTenant is unbound — a stale binding would incorrectly scope
    // this cross-tenant sweep to just the leftover tenant.
    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    $otherStudent = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherStudent->assignRole('student');
    $otherExpired = Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $otherStudent->id,
        'status' => 'pending_approval',
        'source' => 'self_enrol_approval',
        'requested_at' => now()->subDays(7),
        'expires_at' => now()->subDay(),
    ]);

    // beforeEach already bound $this->tenant — simulate it being left over
    // from an unrelated prior job rather than explicitly re-binding here.
    expect(app(CurrentTenant::class)->isBound())->toBeTrue();

    app(ExpirePendingEnrolments::class)->handle();

    expect($otherExpired->fresh()->status)->toBe('expired');
});

it('does not clobber a concurrent approval landing between the sweep\'s query and a row\'s own update', function () {
    // The job's initial query loads every past-due pending_approval row in
    // one SELECT, then updates each row one at a time. Simulate staff
    // approving $racedAgainst on a separate connection in that exact
    // window — landing after the sweep's SELECT already saw it as
    // pending_approval, but before this job gets to write it — by hooking
    // the sweep's own SELECT via DB::listen and racing a raw update in.
    $racedAgainst = ($this->pendingEnrolment)(now()->subDay());
    $control = ($this->pendingEnrolment)(now()->subDay());

    \Illuminate\Support\Facades\DB::listen(function ($query) use ($racedAgainst) {
        if (str_contains($query->sql, 'expires_at')) {
            \Illuminate\Support\Facades\DB::table('enrolments')
                ->where('id', $racedAgainst->id)
                ->update(['status' => 'active']);
        }
    });

    app(ExpirePendingEnrolments::class)->handle();

    expect($racedAgainst->fresh()->status)->toBe('active')
        ->and($control->fresh()->status)->toBe('expired');
});
