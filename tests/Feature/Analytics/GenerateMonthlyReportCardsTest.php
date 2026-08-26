<?php

use App\Domains\Analytics\Jobs\GenerateMonthlyReportCards;
use App\Domains\Analytics\Models\MonthlyReportCard;
use App\Domains\Analytics\Services\ReportCardDataAggregator;
use App\Domains\Analytics\Services\ReportCardPdfGenerator;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Comms\Models\ParentGuardianLink;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Notifications\MonthlyReportCardReady;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
});

function reportCardSweepFixture(Tenant $tenant): User
{
    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->subMonths(2)->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    $student = User::factory()->create(['tenant_id' => $tenant->id]);

    Enrolment::create([
        'batch_id' => $batch->id,
        'user_id' => $student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now()->subMonth(),
    ]);

    return $student;
}

function runReportCardSweep(): void
{
    app(GenerateMonthlyReportCards::class)->handle(
        app(ReportCardDataAggregator::class),
        app(ReportCardPdfGenerator::class),
    );
}

it('generates and sends a report card to the linked parent', function () {
    Notification::fake();

    $student = reportCardSweepFixture($this->tenant);
    $parent = User::factory()->create(['tenant_id' => $this->tenant->id]);
    ParentGuardianLink::create(['parent_user_id' => $parent->id, 'student_user_id' => $student->id, 'relationship' => 'parent']);

    runReportCardSweep();

    $card = MonthlyReportCard::where('student_id', $student->id)->first();

    expect($card)->not->toBeNull()
        ->and($card->status)->toBe('sent')
        ->and($card->pdf_disk_path)->not->toBeNull()
        ->and($card->sent_at)->not->toBeNull()
        ->and($card->period_month->toDateString())->toBe(now()->startOfMonth()->subMonth()->toDateString());

    Storage::disk('local')->assertExists($card->pdf_disk_path);

    Notification::assertSentTo($parent, MonthlyReportCardReady::class, fn ($notification) => $notification->card->id === $card->id
        && $notification->downloadUrl !== '');
});

it('leaves the card generated, not sent, when the student has no linked parent', function () {
    Notification::fake();

    $student = reportCardSweepFixture($this->tenant);

    runReportCardSweep();

    $card = MonthlyReportCard::where('student_id', $student->id)->first();

    expect($card->status)->toBe('generated')
        ->and($card->sent_at)->toBeNull();

    Notification::assertNothingSent();
});

it('never creates a second row or re-sends on a second run for the same month', function () {
    Notification::fake();

    $student = reportCardSweepFixture($this->tenant);
    $parent = User::factory()->create(['tenant_id' => $this->tenant->id]);
    ParentGuardianLink::create(['parent_user_id' => $parent->id, 'student_user_id' => $student->id, 'relationship' => 'parent']);

    runReportCardSweep();
    runReportCardSweep();

    expect(MonthlyReportCard::where('student_id', $student->id)->count())->toBe(1);
    Notification::assertSentToTimes($parent, MonthlyReportCardReady::class, 1);
});

it('does not re-notify a parent already notified when a partially-failed card is retried', function () {
    $student = reportCardSweepFixture($this->tenant);
    $parent1 = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $parent2 = User::factory()->create(['tenant_id' => $this->tenant->id]);
    ParentGuardianLink::create(['parent_user_id' => $parent1->id, 'student_user_id' => $student->id, 'relationship' => 'father']);
    ParentGuardianLink::create(['parent_user_id' => $parent2->id, 'student_user_id' => $student->id, 'relationship' => 'mother']);

    $periodMonth = now()->startOfMonth()->subMonth();
    $card = MonthlyReportCard::create([
        'student_id' => $student->id,
        'period_month' => $periodMonth->toDateString(),
        'status' => 'failed',
        'pdf_disk_path' => 'report-cards/prior.pdf',
    ]);

    // Simulate parent1 already having received the report card before a
    // prior run failed partway through the parent-notify loop (B12
    // review-pass fix — a naive retry would re-send to every linked parent).
    $parent1->notify(new MonthlyReportCardReady($card, $student, 'https://prior.example/download'));

    Notification::fake();

    runReportCardSweep();

    Notification::assertNotSentTo($parent1, MonthlyReportCardReady::class);
    Notification::assertSentTo($parent2, MonthlyReportCardReady::class);
    expect(MonthlyReportCard::where('student_id', $student->id)->first()->status)->toBe('sent');
});

it('retries a card stuck pending from an interrupted prior run', function () {
    Notification::fake();

    $student = reportCardSweepFixture($this->tenant);
    $periodMonth = now()->startOfMonth()->subMonth();

    MonthlyReportCard::withoutEvents(fn () => MonthlyReportCard::create([
        'tenant_id' => $this->tenant->id,
        'student_id' => $student->id,
        'period_month' => $periodMonth->toDateString(),
        'status' => 'pending',
    ]));

    runReportCardSweep();

    $card = MonthlyReportCard::where('student_id', $student->id)->first();

    expect($card->status)->toBe('generated')
        ->and($card->pdf_disk_path)->not->toBeNull();
});

it('skips a non-actively-enrolled student', function () {
    $student = reportCardSweepFixture($this->tenant);
    Enrolment::where('user_id', $student->id)->update(['status' => 'cancelled']);

    runReportCardSweep();

    expect(MonthlyReportCard::where('student_id', $student->id)->exists())->toBeFalse();
});

it('is a real queued job with retry configured', function () {
    expect(app(GenerateMonthlyReportCards::class))
        ->toBeInstanceOf(ShouldQueue::class);

    $job = new GenerateMonthlyReportCards;

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([60, 300, 900]);
});
