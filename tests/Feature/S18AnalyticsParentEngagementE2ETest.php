<?php

use App\Domains\Analytics\Jobs\GenerateMonthlyReportCards;
use App\Domains\Analytics\Models\MonthlyReportCard;
use App\Domains\Analytics\Services\DropOffService;
use App\Domains\Analytics\Services\EngagementMetricsService;
use App\Domains\Analytics\Services\ReportCardDataAggregator;
use App\Domains\Analytics\Services\ReportCardPdfGenerator;
use App\Domains\Analytics\Services\RewatchHeatmapService;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Comms\Models\ParentGuardianLink;
use App\Domains\Content\Models\AttendanceRecord;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Notifications\MonthlyReportCardReady;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * S18 B12 — lifecycle E2E, same posture every prior slice's own final
 * block already established (S14 B18/.../S17 B13): proves the real chains
 * fire end to end through the actual services, not just that each block's
 * own model relationships line up in isolation.
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Mechanics', 'position' => 0]);
    $this->lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Velocity', 'type' => 'video', 'position' => 0, 'video_duration_seconds' => 200]);
    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

it('chains a batch of watch events into consistent engagement/heatmap/drop-off numbers', function () {
    $rewatcher = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $skimmer = User::factory()->create(['tenant_id' => $this->tenant->id]);

    // $this->student and $rewatcher both watch straight through 0-100s then
    // stop (drop off, since the lesson is 200s long). $skimmer only ever
    // touches the 20-30s window, making bucket [20,30) the one bucket both
    // students and the skimmer share — the unambiguous heatmap peak.
    VideoWatchEvent::create(['user_id' => $this->student->id, 'lesson_id' => $this->lesson->id, 'start_s' => 0, 'end_s' => 100]);
    VideoWatchEvent::create(['user_id' => $rewatcher->id, 'lesson_id' => $this->lesson->id, 'start_s' => 0, 'end_s' => 100]);
    VideoWatchEvent::create(['user_id' => $skimmer->id, 'lesson_id' => $this->lesson->id, 'start_s' => 20, 'end_s' => 30]);

    $engagement = app(EngagementMetricsService::class)->ratioFor($this->student, $this->lesson);
    expect($engagement)->toBe(0.5); // 100 of 200s

    $heatmap = app(RewatchHeatmapService::class)->heatmapFor($this->lesson);
    $topWindow = $heatmap->first();
    expect($topWindow['bucket_start_s'])->toBe(20)
        ->and($topWindow['replay_count'])->toBe(3); // student + rewatcher + skimmer all touch 20-30s

    // Both $this->student and $rewatcher's last event ends at 100s (majority
    // drop-off bucket); $skimmer's lone event ends at 30s (minority bucket).
    $dropOff = app(DropOffService::class)->dropOffPointFor($this->lesson);
    expect($dropOff)->toBe(100);
});

it('chains a months attendance/quiz/engagement data through report-card generation to a sent WhatsApp PDF', function () {
    Notification::fake();
    Storage::fake('local');

    $periodMonth = now()->startOfMonth()->subMonth();

    $batch = Batch::create([
        'course_id' => $this->lesson->section->course_id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => $periodMonth->clone()->subMonth()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    Enrolment::create(['batch_id' => $batch->id, 'user_id' => $this->student->id, 'status' => 'active', 'source' => 'manual_add', 'requested_at' => $periodMonth]);

    $session = BatchSession::create(['batch_id' => $batch->id, 'starts_at' => $periodMonth->clone()->addDay(), 'duration_minutes' => 60, 'position' => 0]);
    AttendanceRecord::create(['attendable_type' => BatchSession::class, 'attendable_id' => $session->id, 'user_id' => $this->student->id, 'status' => 'present', 'source' => 'manual', 'recorded_at' => $periodMonth->clone()->addDay()]);

    $event = VideoWatchEvent::create(['user_id' => $this->student->id, 'lesson_id' => $this->lesson->id, 'start_s' => 0, 'end_s' => 100]);
    $event->forceFill(['created_at' => $periodMonth->clone()->addDay()])->save();

    $parent = User::factory()->create(['tenant_id' => $this->tenant->id]);
    ParentGuardianLink::create(['parent_user_id' => $parent->id, 'student_user_id' => $this->student->id, 'relationship' => 'father']);

    app(GenerateMonthlyReportCards::class)->handle(app(ReportCardDataAggregator::class), app(ReportCardPdfGenerator::class));

    $card = MonthlyReportCard::where('student_id', $this->student->id)->firstOrFail();

    expect($card->status)->toBe('sent')
        ->and($card->pdf_disk_path)->not->toBeNull();

    Storage::disk('local')->assertExists($card->pdf_disk_path);
    expect(substr(Storage::disk('local')->get($card->pdf_disk_path), 0, 4))->toBe('%PDF');

    Notification::assertSentTo($parent, MonthlyReportCardReady::class, fn ($n) => $n->card->id === $card->id);
});
