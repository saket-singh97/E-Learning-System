<?php

use App\Domains\AI\Models\DoubtChatMessage;
use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Models\LessonArtifactChunk;
use App\Domains\AI\Models\WhatsAppLink;
use App\Domains\AI\Services\WeakTopicDetectionService;
use App\Domains\Analytics\Services\PeerComparisonService;
use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAnswer;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Services\FeeAnalyticsService;
use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\AiProvider;
use App\Integrations\Drivers\FakeAiDriver;
use App\Integrations\TranscriptResult;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/**
 * S24 B14 — the one thing no per-block test could cover: real stories
 * chained end to end through the actual services, same posture every
 * prior slice's own final block established (e.g. S23CoachingMarketDifferentiatorsE2ETest).
 *
 * Design doc §5 names 4 chains; the first two ("a WhatsApp text doubt →
 * resolved lesson → answered through DoubtChatService" and "a voice note
 * → transcribed → answered through the same path") share one real HTTP
 * round trip through /api/webhooks/whatsapp per message, same signature-
 * verified route every per-block test already exercises — chained here
 * as one story on the same linked student/lesson, proving both message
 * types land in the exact same DoubtChatService path (not just similar
 * per-block assertions in isolation).
 */
uses(RefreshDatabase::class);

class E2EFixedTranscriptDriver extends FakeAiDriver
{
    public function transcribe(string $diskPath): TranscriptResult
    {
        return new TranscriptResult(text: 'Velocity is displacement over time.', segments: []);
    }
}

function e2eWhatsAppWebhookPost(string $payload, string $secret): \Illuminate\Testing\TestResponse
{
    return test()->call('POST', '/api/webhooks/whatsapp', [], [], [], [
        'HTTP_X-Webhook-Signature' => hash_hmac('sha256', $payload, $secret),
        'CONTENT_TYPE' => 'application/json',
    ], $payload);
}

it('answers a linked student\'s WhatsApp text doubt and a voice note through the same real DoubtChatService path', function () {
    config(['services.aisensy.webhook_secret' => 'e2e-secret']);
    test()->seed(RoleSeeder::class);

    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Mechanics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Velocity', 'type' => 'video', 'position' => 0, 'video_status' => 'ready']);

    $artifact = LessonArtifact::create(['lesson_id' => $lesson->id, 'version' => 1, 'source_asset_version' => 'v1', 'status' => 'ready']);
    LessonArtifactChunk::create([
        'tenant_id' => $tenant->id,
        'lesson_artifact_id' => $artifact->id,
        'chunk_idx' => 0,
        'start_s' => 0,
        'end_s' => 5,
        'text' => 'Velocity is displacement over time.',
        'embedding' => app(AiProvider::class)->embed('Velocity is displacement over time.'),
    ]);

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');
    WhatsAppLink::create(['user_id' => $student->id, 'phone_e164' => '+919800000001', 'verified_at' => now()]);

    // Text doubt — real HTTP round trip through the signature-verified webhook.
    $textPayload = json_encode(['from' => '+919800000001', 'type' => 'text', 'text' => ['body' => 'Velocity is displacement over time.']]);
    e2eWhatsAppWebhookPost($textPayload, 'e2e-secret')->assertOk();

    $textMessage = DoubtChatMessage::where('user_id', $student->id)->where('channel', 'whatsapp')->sole();
    expect($textMessage->lesson_id)->toBe($lesson->id)
        ->and($textMessage->answer)->not->toBeEmpty();

    // Voice note — transcribes to the same question text, re-enters the
    // exact same handleDoubt() path as the text message above.
    app()->bind(AiProvider::class, E2EFixedTranscriptDriver::class);
    Http::fake(['https://cdn.example.com/note.ogg' => Http::response('fake audio bytes')]);

    $audioPayload = json_encode(['from' => '+919800000001', 'type' => 'audio', 'audio' => ['url' => 'https://cdn.example.com/note.ogg']]);
    e2eWhatsAppWebhookPost($audioPayload, 'e2e-secret')->assertOk();

    $messages = DoubtChatMessage::where('user_id', $student->id)->where('channel', 'whatsapp')->get();
    expect($messages)->toHaveCount(2);

    $voiceMessage = $messages->last();
    expect($voiceMessage->lesson_id)->toBe($lesson->id)
        ->and($voiceMessage->question)->toBe('Velocity is displacement over time.')
        ->and($voiceMessage->answer)->not->toBeEmpty();
});

it('computes fee analytics numbers matching a real seeded set of paid/unpaid/overdue invoices', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $makeInvoice = function (string $status, float $amount, string $dueDate) use ($tenant): FeeInvoice {
        return FeeInvoice::create([
            'user_id' => User::factory()->create(['tenant_id' => $tenant->id])->id,
            'sequence_number' => FeeInvoice::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count() + 1,
            'invoice_number' => 'INV-'.uniqid(),
            'description' => 'Fee',
            'amount' => $amount,
            'gst_amount' => 0,
            'total_amount' => $amount,
            'due_date' => $dueDate,
            'status' => $status,
        ]);
    };

    $makeInvoice('paid', 2000, now()->toDateString());
    $makeInvoice('pending', 1000, now()->addDays(15)->toDateString());
    $makeInvoice('overdue', 500, now()->subDays(10)->toDateString());

    $analytics = app(FeeAnalyticsService::class);

    // 2000 paid / 3500 non-cancelled total.
    expect($analytics->collectionRate($tenant))->toBe(round(2000 / 3500 * 100, 2))
        ->and($analytics->projectedRevenue($tenant))->toBe(1000.0);

    $ageing = $analytics->defaulterAgeing($tenant);
    expect($ageing['0-30'])->toBe(['count' => 1, 'amount' => 500.0]);
});

it('matches a student\'s own percentile on a weak topic to a hand-computed rank among real seeded classmates', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid()]);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Velocity', 'type' => 'video', 'position' => 0]);
    $quiz = Quiz::create(['lesson_id' => $lesson->id, 'title' => 'Quiz', 'status' => 'published', 'blueprint' => [], 'time_limit_minutes' => 20, 'max_attempts' => 5]);

    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $strongClassmate = User::factory()->create(['tenant_id' => $tenant->id]);
    $weakClassmate = User::factory()->create(['tenant_id' => $tenant->id]);

    foreach ([$student, $strongClassmate, $weakClassmate] as $enrollee) {
        Enrolment::create(['batch_id' => $batch->id, 'user_id' => $enrollee->id, 'status' => 'active', 'source' => 'manual_add', 'requested_at' => now()]);
    }

    $makeAttempt = function (User $user, array $correctness) use ($quiz, $course): void {
        $attempt = QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'user_id' => $user->id,
            'question_ids' => [],
            'status' => 'submitted',
            'started_at' => now(),
            'expires_at' => now()->addHour(),
            'max_score' => 10,
        ]);

        foreach ($correctness as $isCorrect) {
            $question = Question::create([
                'course_id' => $course->id,
                'type' => 'mcq',
                'stem' => 'Q?',
                'correct_answer' => ['A'],
                'difficulty' => 'medium',
                'concept' => 'Kinematics',
                'source' => 'manual',
            ]);

            QuizAnswer::create(['quiz_attempt_id' => $attempt->id, 'question_id' => $question->id, 'is_correct' => $isCorrect, 'status' => 'auto_graded']);
        }
    };

    // Student: 1/3 correct (ratio 0.33, weak). Strong classmate: 3/3.
    // Weak classmate: 0/3.
    $makeAttempt($student, [true, false, false]);
    $makeAttempt($strongClassmate, [true, true, true]);
    $makeAttempt($weakClassmate, [false, false, false]);

    $weakTopics = app(WeakTopicDetectionService::class)->weakTopicsFor($student, $course->id);
    expect($weakTopics->pluck('concept')->all())->toContain('Kinematics');

    // Denominator is every participant including the student themself
    // (same convention TestSeriesRankingService's own percentile already
    // uses) — student beats 1 of 3 total (the weak classmate) => 33.3%.
    expect(app(PeerComparisonService::class)->percentileFor($student, $batch, 'Kinematics'))->toBe(33.3);
});
