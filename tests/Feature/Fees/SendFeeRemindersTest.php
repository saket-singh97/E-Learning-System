<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Services\BatchEnrolmentService;
use App\Domains\Content\Models\Course;
use App\Domains\Fees\Jobs\SendFeeReminders;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\FeeReminderDue;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

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

it('is a real queued job with retry configured', function () {
    // S9 B10: this was a plain class before — Schedule::job() ran it inline
    // (dispatchNow()), so $tries/backoff() would have been dead
    // configuration. Confirms the conversion actually took, not just that
    // the properties exist.
    expect(app(SendFeeReminders::class))
        ->toBeInstanceOf(ShouldQueue::class);

    $job = new SendFeeReminders;

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([60, 300, 900]);
});

it('sends the reminder even when CurrentTenant is left stale-bound to a different tenant at call time', function () {
    // Regression for the exact risk converting this job to a real queued
    // job introduced (S9 B10): a persistent Horizon worker can run any
    // other tenant-bound job right before this one in the same PHP
    // process — beforeEach already binds $this->tenant, left as-is (not
    // cleared) all the way into handle() below, proving handle()'s own
    // clear() call is what makes the sweep see every tenant regardless.
    Notification::fake();

    $invoice = ($this->invoice)();
    $invoice->update(['due_date' => now()->toDateString()]);

    expect(app(CurrentTenant::class)->id())->toBe($this->tenant->id);

    app(SendFeeReminders::class)->handle();

    Notification::assertSentTo($invoice->user, FeeReminderDue::class);
});

it('sends an upcoming reminder 3 days before due_date', function () {
    Notification::fake();

    $invoice = ($this->invoice)();
    $invoice->update(['due_date' => now()->addDays(3)->toDateString()]);

    app(SendFeeReminders::class)->handle();

    Notification::assertSentTo($invoice->user, FeeReminderDue::class, fn ($n) => $n->stage === 'upcoming' && $n->invoice->is($invoice));
});

it('sends a due_today reminder on the due date itself', function () {
    Notification::fake();

    $invoice = ($this->invoice)();
    $invoice->update(['due_date' => now()->toDateString()]);

    app(SendFeeReminders::class)->handle();

    Notification::assertSentTo($invoice->user, FeeReminderDue::class, fn ($n) => $n->stage === 'due_today');
});

it('sends an overdue reminder 3 days after due_date, once flagged overdue', function () {
    Notification::fake();

    $invoice = ($this->invoice)();
    $invoice->update(['due_date' => now()->subDays(3)->toDateString(), 'status' => 'overdue']);

    app(SendFeeReminders::class)->handle();

    Notification::assertSentTo($invoice->user, FeeReminderDue::class, fn ($n) => $n->stage === 'overdue');
});

it('sends nothing for an offset that matches none of the three reminder days', function () {
    Notification::fake();

    $invoice = ($this->invoice)();
    $invoice->update(['due_date' => now()->addDays(1)->toDateString()]);

    app(SendFeeReminders::class)->handle();

    Notification::assertNothingSent();
});

it('sends nothing for a paid invoice even on a matching offset', function () {
    Notification::fake();

    $invoice = ($this->invoice)();
    $invoice->update(['due_date' => now()->toDateString(), 'status' => 'paid', 'paid_at' => now()]);

    app(SendFeeReminders::class)->handle();

    Notification::assertNothingSent();
});

it('sends nothing for a cancelled invoice even on a matching offset', function () {
    Notification::fake();

    $invoice = ($this->invoice)();
    $invoice->update(['due_date' => now()->toDateString(), 'status' => 'cancelled']);

    app(SendFeeReminders::class)->handle();

    Notification::assertNothingSent();
});

it('renders stage-appropriate mail content', function () {
    Notification::fake();

    $invoice = ($this->invoice)();
    $invoice->update(['due_date' => now()->toDateString()]);

    app(SendFeeReminders::class)->handle();

    Notification::assertSentTo($invoice->user, FeeReminderDue::class, function (FeeReminderDue $notification) use ($invoice) {
        $mail = $notification->toMail($invoice->user);

        expect($mail->subject)->toBe('Payment due today')
            ->and($mail->introLines[0])->toContain('due today')
            ->and($mail->introLines[0])->toContain('1,180.00');

        return true;
    });
});

it('now dispatches on the WhatsApp channel alongside mail and database', function () {
    Notification::fake();

    $invoice = ($this->invoice)();
    $invoice->update(['due_date' => now()->toDateString()]);

    app(SendFeeReminders::class)->handle();

    Notification::assertSentTo($invoice->user, FeeReminderDue::class, function (FeeReminderDue $notification) use ($invoice) {
        expect($notification->via($invoice->user))->toBe(['mail', 'database', WhatsAppChannel::class]);

        return true;
    });
});

it('sends only one reminder per invoice per stage even if the job runs twice the same day', function () {
    // No Notification::fake() here on purpose — the dedupe guard
    // (alreadySentToday()) reads back the real 'database' channel row,
    // same reasoning SendAbsenceAlertTest's own equivalent case documents.
    // A real queued job (S9 B10 made this one ShouldQueue) can legitimately
    // run twice for the same schedule tick via a queue-level retry after a
    // transient failure partway through the invoice loop — this is exactly
    // what that retry would otherwise turn into a duplicate-send bug.
    $invoice = ($this->invoice)();
    $invoice->update(['due_date' => now()->toDateString()]);

    app(SendFeeReminders::class)->handle();
    app(SendFeeReminders::class)->handle();

    expect($invoice->user->notifications()->where('type', FeeReminderDue::class)->count())->toBe(1);
});

it('does not let a same-day reminder for one stage suppress a different stage for the same invoice', function () {
    $invoice = ($this->invoice)();
    $invoice->update(['due_date' => now()->toDateString()]);

    app(SendFeeReminders::class)->handle();

    // Flip the same invoice's due_date so the *next* handle() call would
    // also match the 'upcoming' offset (3 days out) — a naive per-invoice
    // (not per-invoice-per-stage) dedupe key would wrongly suppress this.
    $invoice->update(['due_date' => now()->addDays(3)->toDateString()]);

    app(SendFeeReminders::class)->handle();

    expect($invoice->user->notifications()->where('type', FeeReminderDue::class)->count())->toBe(2);
});

it('renders a WhatsApp reminder naming the stage, amount, and due date', function () {
    Notification::fake();

    $invoice = ($this->invoice)();
    $invoice->update(['due_date' => now()->addDays(3)->toDateString()]);

    app(SendFeeReminders::class)->handle();

    Notification::assertSentTo($invoice->user, FeeReminderDue::class, function (FeeReminderDue $notification) use ($invoice) {
        $message = $notification->toWhatsApp($invoice->user);

        expect($message->template)->toBe('fee_reminder')
            ->and($message->params['stage'])->toBe('upcoming')
            ->and($message->params['amount'])->toBe('1,180.00')
            ->and($message->params['due_date'])->toBe($invoice->due_date->toDateString());

        return true;
    });
});
