<?php

use App\Domains\AI\Models\DoubtChatMessage;
use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Models\LessonArtifactChunk;
use App\Domains\AI\Models\PhotoDoubtQuery;
use App\Domains\AI\Models\WhatsAppLink;
use App\Domains\AI\Services\PhotoDoubtService;
use App\Domains\AI\Services\WhatsAppLinkService;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\AiProvider;
use App\Integrations\CommsProvider;
use App\Integrations\Drivers\FakeAiDriver;
use App\Integrations\Drivers\FakeWhatsAppDriver;
use App\Integrations\TranscriptResult;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

class FixedTranscriptDriver extends FakeAiDriver
{
    public function transcribe(string $diskPath): TranscriptResult
    {
        return new TranscriptResult(text: 'Velocity is displacement over time.', segments: []);
    }
}

class ThrowingTranscribeDriver extends FakeAiDriver
{
    public function transcribe(string $diskPath): TranscriptResult
    {
        throw new RuntimeException('vendor transcription failed');
    }
}

class RecordingCommsDriver extends FakeWhatsAppDriver
{
    /** @var array<int, array{to: string, text: string}> */
    public static array $sent = [];

    public function sendSessionMessage(string $toE164, string $text): array
    {
        static::$sent[] = ['to' => $toE164, 'text' => $text];

        return parent::sendSessionMessage($toE164, $text);
    }
}

/**
 * Same requirement SemanticSearchServiceTest/DoubtChatTest already document:
 * nearestNeighbors() filters ->whereNotNull('embedding').
 */
function whatsappChunk(int $lessonArtifactId, int $tenantId, string $text): LessonArtifactChunk
{
    return LessonArtifactChunk::create([
        'tenant_id' => $tenantId,
        'lesson_artifact_id' => $lessonArtifactId,
        'chunk_idx' => 0,
        'start_s' => 0,
        'end_s' => 5,
        'text' => $text,
        'embedding' => app(AiProvider::class)->embed($text),
    ]);
}

function whatsappWebhookPost(string $payload, string $secret): \Illuminate\Testing\TestResponse
{
    return test()->call('POST', '/api/webhooks/whatsapp', [], [], [], [
        'HTTP_X-Webhook-Signature' => hash_hmac('sha256', $payload, $secret),
        'CONTENT_TYPE' => 'application/json',
    ], $payload);
}

beforeEach(function () {
    config(['services.aisensy.webhook_secret' => 'test-secret']);

    test()->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid(), 'status' => 'published']);
    $course = $this->course;
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $this->lesson = Lesson::create([
        'course_section_id' => $section->id,
        'title' => 'Velocity',
        'type' => 'video',
        'position' => 0,
        'video_status' => 'ready',
    ]);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');
});

it('answers a linked student\'s text doubt and tags the message channel/source_phone', function () {
    $artifact = LessonArtifact::create(['lesson_id' => $this->lesson->id, 'version' => 1, 'source_asset_version' => 'v1', 'status' => 'ready']);
    whatsappChunk($artifact->id, $this->tenant->id, 'Velocity is displacement over time.');

    WhatsAppLink::create(['user_id' => $this->student->id, 'phone_e164' => '+919876500010', 'verified_at' => now()]);

    $payload = json_encode(['from' => '+919876500010', 'type' => 'text', 'text' => ['body' => 'Velocity is displacement over time.']]);
    whatsappWebhookPost($payload, 'test-secret')->assertOk();

    $message = DoubtChatMessage::where('user_id', $this->student->id)->sole();
    expect($message->lesson_id)->toBe($this->lesson->id)
        ->and($message->channel)->toBe('whatsapp')
        ->and($message->source_phone)->toBe('+919876500010')
        ->and($message->answer)->not->toBeEmpty();
});

// S24 B13 review pass — DoubtChatService::ask() aborts (403/404) on a
// canStream()-blocked lesson even when isVisibleTo()/search() already
// matched it (fee/attendance/prerequisite gates search() never checks),
// and that used to propagate as a raw HTTP error out of the webhook
// instead of a reply.
it('replies with an access-denied message instead of a raw HTTP error when the matched lesson is fee-blocked', function () {
    $this->tenant->fee_grace_period_days = 0;
    $this->tenant->save();

    $artifact = LessonArtifact::create(['lesson_id' => $this->lesson->id, 'version' => 1, 'source_asset_version' => 'v1', 'status' => 'ready']);
    whatsappChunk($artifact->id, $this->tenant->id, 'Velocity is displacement over time.');

    WhatsAppLink::create(['user_id' => $this->student->id, 'phone_e164' => '+919876500095', 'verified_at' => now()]);

    $batch = Batch::create([
        'course_id' => $this->course->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    $enrolment = Enrolment::create(['batch_id' => $batch->id, 'user_id' => $this->student->id, 'status' => 'active', 'source' => 'manual_add', 'requested_at' => now()]);
    FeeInvoice::create([
        'user_id' => $this->student->id,
        'enrolment_id' => $enrolment->id,
        'sequence_number' => 1,
        'invoice_number' => 'INV-'.uniqid(),
        'description' => 'Fee',
        'amount' => 100,
        'gst_amount' => 0,
        'total_amount' => 100,
        'due_date' => now()->subDays(5)->toDateString(),
        'status' => 'overdue',
    ]);

    $payload = json_encode(['from' => '+919876500095', 'type' => 'text', 'text' => ['body' => 'Velocity is displacement over time.']]);
    whatsappWebhookPost($payload, 'test-secret')->assertOk();

    expect(DoubtChatMessage::count())->toBe(0);
});

it('replies with a link-prompt and writes no row for an unlinked number', function () {
    $payload = json_encode(['from' => '+919876500099', 'type' => 'text', 'text' => ['body' => 'What is velocity?']]);
    whatsappWebhookPost($payload, 'test-secret')->assertOk();

    expect(DoubtChatMessage::count())->toBe(0);
});

it('replies cleanly and writes no row when nothing semantically matches', function () {
    WhatsAppLink::create(['user_id' => $this->student->id, 'phone_e164' => '+919876500020', 'verified_at' => now()]);

    // No LessonArtifact/chunks seeded at all — search() has nothing to match.
    $payload = json_encode(['from' => '+919876500020', 'type' => 'text', 'text' => ['body' => 'Completely unrelated question']]);
    whatsappWebhookPost($payload, 'test-secret')->assertOk();

    expect(DoubtChatMessage::count())->toBe(0);
});

it('rejects a webhook with a bad signature', function () {
    $payload = json_encode(['from' => '+919876500030', 'type' => 'text', 'text' => ['body' => 'hi']]);

    test()->call('POST', '/api/webhooks/whatsapp', [], [], [], [
        'HTTP_X-Webhook-Signature' => 'forged',
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertStatus(400);

    expect(DoubtChatMessage::count())->toBe(0);
});

it('transcribes a voice note and produces the same DoubtChatMessage shape a text doubt would', function () {
    app()->bind(AiProvider::class, FixedTranscriptDriver::class);

    $artifact = LessonArtifact::create(['lesson_id' => $this->lesson->id, 'version' => 1, 'source_asset_version' => 'v1', 'status' => 'ready']);
    whatsappChunk($artifact->id, $this->tenant->id, 'Velocity is displacement over time.');

    WhatsAppLink::create(['user_id' => $this->student->id, 'phone_e164' => '+919876500050', 'verified_at' => now()]);

    Http::fake(['https://cdn.example.com/note.ogg' => Http::response('fake audio bytes')]);

    $payload = json_encode(['from' => '+919876500050', 'type' => 'audio', 'audio' => ['url' => 'https://cdn.example.com/note.ogg']]);
    whatsappWebhookPost($payload, 'test-secret')->assertOk();

    $message = DoubtChatMessage::where('user_id', $this->student->id)->sole();
    expect($message->lesson_id)->toBe($this->lesson->id)
        ->and($message->channel)->toBe('whatsapp')
        ->and($message->source_phone)->toBe('+919876500050')
        ->and($message->question)->toBe('Velocity is displacement over time.')
        ->and($message->answer)->not->toBeEmpty();
});

it('replies with a try-typing message and writes no row when transcription fails', function () {
    app()->bind(AiProvider::class, ThrowingTranscribeDriver::class);
    app()->bind(CommsProvider::class, RecordingCommsDriver::class);
    RecordingCommsDriver::$sent = [];

    WhatsAppLink::create(['user_id' => $this->student->id, 'phone_e164' => '+919876500060', 'verified_at' => now()]);

    Http::fake(['https://cdn.example.com/bad.ogg' => Http::response('fake audio bytes')]);

    $payload = json_encode(['from' => '+919876500060', 'type' => 'audio', 'audio' => ['url' => 'https://cdn.example.com/bad.ogg']]);
    whatsappWebhookPost($payload, 'test-secret')->assertOk();

    expect(DoubtChatMessage::count())->toBe(0)
        ->and(RecordingCommsDriver::$sent)->toHaveCount(1)
        ->and(RecordingCommsDriver::$sent[0]['to'])->toBe('+919876500060')
        ->and(RecordingCommsDriver::$sent[0]['text'])->toContain('try typing');
});

it('creates an identical PhotoDoubtQuery row/answer shape via WhatsApp as an in-app upload, distinguished only by channel', function () {
    Storage::fake('local');
    app()->bind(CommsProvider::class, RecordingCommsDriver::class);
    RecordingCommsDriver::$sent = [];

    WhatsAppLink::create(['user_id' => $this->student->id, 'phone_e164' => '+919876500070', 'verified_at' => now()]);

    $inAppQuery = app(PhotoDoubtService::class)->submit(
        UploadedFile::fake()->image('question.jpg'),
        $this->student,
        null,
    );

    Http::fake(['https://cdn.example.com/photo.jpg' => Http::response('fake image bytes')]);

    $payload = json_encode(['from' => '+919876500070', 'type' => 'image', 'image' => ['url' => 'https://cdn.example.com/photo.jpg']]);
    whatsappWebhookPost($payload, 'test-secret')->assertOk();

    $whatsappQuery = PhotoDoubtQuery::where('source_phone', '+919876500070')->sole();

    expect($whatsappQuery->channel)->toBe('whatsapp')
        ->and($whatsappQuery->status)->toBe('ready')
        ->and($whatsappQuery->solution)->not->toBeEmpty()
        ->and($inAppQuery->fresh()->status)->toBe('ready')
        ->and($inAppQuery->fresh()->channel)->toBe('web')
        ->and($inAppQuery->fresh()->source_phone)->toBeNull();

    // Only the WhatsApp-sourced query triggers a reply — the in-app one
    // (channel=web) is a silent no-op in ProcessPhotoDoubtQuery's own
    // notifyWhatsApp() guard.
    expect(RecordingCommsDriver::$sent)->toHaveCount(1)
        ->and(RecordingCommsDriver::$sent[0]['to'])->toBe('+919876500070');
});

it('replies with a link-prompt and writes no PhotoDoubtQuery row for an unlinked number sending a photo', function () {
    $payload = json_encode(['from' => '+919876500080', 'type' => 'image', 'image' => ['url' => 'https://cdn.example.com/photo.jpg']]);
    whatsappWebhookPost($payload, 'test-secret')->assertOk();

    expect(PhotoDoubtQuery::count())->toBe(0);
});

it('verifies a linking code sent as a WhatsApp text message', function () {
    $code = app(WhatsAppLinkService::class)->generateCode($this->student);

    $payload = json_encode(['from' => '+919876500040', 'type' => 'text', 'text' => ['body' => "LINK {$code}"]]);
    whatsappWebhookPost($payload, 'test-secret')->assertOk();

    $link = WhatsAppLink::where('phone_e164', '+919876500040')->sole();
    expect($link->user_id)->toBe($this->student->id)
        ->and($link->verified_at)->not->toBeNull();
});
