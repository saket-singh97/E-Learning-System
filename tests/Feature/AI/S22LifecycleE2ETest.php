<?php

use App\Domains\AI\Models\CourseOutlineDraft;
use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Models\LessonArtifactChunk;
use App\Domains\AI\Services\DoubtChatService;
use App\Domains\AI\Services\LessonPlanAssistant;
use App\Domains\Assessments\Models\DailyPracticeSet;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Services\DailyPracticeSetGenerator;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Events\LessonVideoReady;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Fees\Jobs\RenewStudentSubscriptions;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Models\FeePayment;
use App\Domains\Fees\Models\StudentMembershipPlan;
use App\Domains\Fees\Models\StudentSubscription;
use App\Domains\Fees\Services\FeeInvoiceService;
use App\Domains\Fees\Services\PaymentReconciler;
use App\Domains\Growth\Models\AffiliateCode;
use App\Domains\Growth\Models\AffiliateReferral;
use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\Drivers\FakeRazorpayDriver;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * S22 B13 — real story chains end to end, same posture every prior slice's
 * own final block established (S21LifecycleE2ETest, S20LifecycleE2ETest).
 * Each scenario drives real services/events through the real ingestion
 * pipeline against FakeAiDriver, not a shortcut flip of a status column.
 */
it('runs a live class through ingestion into a real auto-quiz and that days DPP', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create([
        'course_section_id' => $section->id,
        'title' => 'Live Class: Velocity',
        'type' => 'live_session',
        'position' => 0,
        'video_disk_path' => "videos/{$tenant->id}/lecture.mp4",
        'video_status' => 'ready',
    ]);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch A',
        'slug' => 'batch-a-'.uniqid(),
        'starts_on' => now()->subMonth()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    BatchSession::create([
        'batch_id' => $batch->id,
        'lesson_id' => $lesson->id,
        'starts_at' => now()->subHour(),
        'duration_minutes' => 60,
        'position' => 0,
    ]);

    // The live class "ends" -> ingestion runs for real against FakeAiDriver,
    // firing LessonArtifactReady, which GenerateQuizFromLiveClass (B2) and
    // GenerateLessonPdfNotes (B0) both additionally listen on.
    event(new LessonVideoReady($lesson->id, $tenant->id));

    $artifact = LessonArtifact::where('lesson_id', $lesson->id)->sole();
    expect($artifact->status)->toBe('ready');

    $quiz = Quiz::where('lesson_id', $lesson->id)->sole();
    expect($quiz->status)->toBe('draft')
        ->and($quiz->blueprint)->not->toBeEmpty();

    // DPP (B3/B4) aggregates the same lesson's own seed_questions, since
    // its BatchSession was taught (starts_at) before today's cutoff.
    $dpp = app(DailyPracticeSetGenerator::class)->generateFor($batch, now());
    expect($dpp)->toBeInstanceOf(DailyPracticeSet::class)
        ->and($dpp->question_ids)->not->toBeEmpty();
});

it('answers a students doubt grounded in the chunk nearest the timestamp they paused at', function () {
    $this->seed(Database\Seeders\RoleSeeder::class);

    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create([
        'course_section_id' => $section->id,
        'title' => 'Velocity',
        'type' => 'video',
        'position' => 0,
        'video_disk_path' => "videos/{$tenant->id}/lecture.mp4",
        'video_status' => 'ready',
    ]);
    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    event(new LessonVideoReady($lesson->id, $tenant->id));

    $artifact = LessonArtifact::where('lesson_id', $lesson->id)->sole();
    $chunks = LessonArtifactChunk::where('lesson_artifact_id', $artifact->id)->orderBy('chunk_idx')->get();
    expect($chunks)->not->toBeEmpty();

    // Student pauses partway into a real chunk the pipeline actually
    // produced and asks about "this moment".
    $targetChunk = $chunks->get(min(1, $chunks->count() - 1));
    $atSecond = (int) (($targetChunk->start_s + $targetChunk->end_s) / 2);

    $message = app(DoubtChatService::class)->ask($lesson, $student, 'Explain this part', $atSecond);

    expect($message->cited_chunk_idxs)->toContain($targetChunk->chunk_idx);
});

it('drafts a teachers next lesson plan grounded in the real syllabus_map once ingestion completes', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    $teacher = User::factory()->create(['tenant_id' => $tenant->id]);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Thermodynamics', 'position' => 0]);
    $lesson = Lesson::create([
        'course_section_id' => $section->id,
        'title' => 'Laws of Thermodynamics',
        'type' => 'video',
        'position' => 0,
        'video_disk_path' => "videos/{$tenant->id}/lecture.mp4",
        'video_status' => 'ready',
    ]);

    event(new LessonVideoReady($lesson->id, $tenant->id));

    $artifact = LessonArtifact::where('lesson_id', $lesson->id)->sole();
    expect($artifact->syllabus_map)->not->toBeEmpty();

    $draft = app(LessonPlanAssistant::class)->generateFor($lesson, $teacher);

    expect($draft)->toBeInstanceOf(CourseOutlineDraft::class)
        ->and($draft->generated_outline)->not->toBeEmpty();
});

it('renews a due subscription and auto-confirms an affiliate commission on real payment capture', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'lifecycle-'.uniqid()]);
    app(CurrentTenant::class)->set($tenant);

    // Z1 — a subscription past its period renews via the daily sweep.
    $plan = StudentMembershipPlan::create([
        'name' => 'Monthly Plan',
        'price' => 499,
        'billing_interval' => 'monthly',
        'trial_days' => 7,
        'status' => 'active',
    ]);
    $subscriber = User::factory()->create(['tenant_id' => $tenant->id]);
    $subscription = StudentSubscription::create([
        'user_id' => $subscriber->id,
        'student_membership_plan_id' => $plan->id,
        'status' => 'active',
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now()->subDay(),
    ]);

    app(CurrentTenant::class)->clear();
    (new RenewStudentSubscriptions)->handle();
    app(CurrentTenant::class)->set($tenant);

    // Billed but not yet paid — past_due, not a free extra period (S22 B13
    // review pass fix). The period only actually advances once the
    // renewal invoice this sweep just created is genuinely captured.
    expect($subscription->fresh()->status)->toBe('past_due');

    $renewalPayment = FeePayment::where(
        'fee_invoice_id',
        FeeInvoice::where('user_id', $subscriber->id)->sole()->id,
    )->sole();
    $renewalPayload = json_encode([
        'event' => 'payment.captured',
        'payload' => [
            'payment' => ['entity' => ['id' => 'pay_lifecycle_renewal', 'order_id' => $renewalPayment->provider_order_id]],
            'order' => ['entity' => ['id' => $renewalPayment->provider_order_id, 'notes' => ['tenant_id' => (string) $tenant->id]]],
        ],
    ]);
    app(PaymentReconciler::class)->reconcile(
        $renewalPayload,
        hash_hmac('sha256', $renewalPayload, FakeRazorpayDriver::FAKE_SECRET),
    );

    $renewed = $subscription->fresh();
    expect($renewed->status)->toBe('active')
        ->and($renewed->current_period_end->isFuture())->toBeTrue();

    // Z2 — a captured enrolment payment with a pending affiliate referral
    // confirms its own commission automatically, through the real
    // webhook path (PaymentReconciler::reconcile), not a direct event().
    $affiliateCode = AffiliateCode::create([
        'owner_user_id' => User::factory()->create(['tenant_id' => $tenant->id])->id,
        'code' => 'LIFECYCLE1',
        'commission_type' => 'percentage',
        'commission_value' => 10,
        'status' => 'active',
    ]);
    $enrolCourse = Course::create(['title' => 'Chemistry', 'slug' => 'chem-'.uniqid(), 'status' => 'published']);
    $batch = Batch::create([
        'course_id' => $enrolCourse->id,
        'title' => 'Chem Batch',
        'slug' => 'chem-batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    $referredStudent = User::factory()->create(['tenant_id' => $tenant->id]);
    $enrolment = Enrolment::create([
        'batch_id' => $batch->id,
        'user_id' => $referredStudent->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);
    $invoice = FeeInvoice::create([
        'enrolment_id' => $enrolment->id,
        'user_id' => $referredStudent->id,
        'sequence_number' => 1,
        'invoice_number' => 'INV-'.uniqid(),
        'description' => 'Batch fee',
        'amount' => 1000,
        'gst_amount' => 180,
        'total_amount' => 1180,
        'due_date' => now()->toDateString(),
    ]);
    $payment = app(FeeInvoiceService::class)->createPaymentOrder($invoice);
    $referral = AffiliateReferral::create([
        'affiliate_code_id' => $affiliateCode->id,
        'referred_user_id' => $referredStudent->id,
        'fee_payment_id' => $payment->id,
        'commission_amount' => 0,
        'status' => 'pending',
    ]);

    $payload = json_encode([
        'event' => 'payment.captured',
        'payload' => [
            'payment' => ['entity' => ['id' => 'pay_lifecycle', 'order_id' => $payment->provider_order_id]],
            'order' => ['entity' => ['id' => $payment->provider_order_id, 'notes' => ['tenant_id' => (string) $tenant->id]]],
        ],
    ]);
    $signature = hash_hmac('sha256', $payload, FakeRazorpayDriver::FAKE_SECRET);

    app(PaymentReconciler::class)->reconcile($payload, $signature);

    expect($referral->fresh()->status)->toBe('confirmed')
        ->and((float) $referral->fresh()->commission_amount)->toBe(118.0);
});
