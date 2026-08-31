<?php

use App\Domains\AI\Models\DoubtChatMessage;
use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Models\LessonArtifactChunk;
use App\Domains\AI\Models\WhatsAppLink;
use App\Domains\AI\Services\WhatsAppLinkService;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\AiProvider;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid(), 'status' => 'published']);
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

it('verifies a linking code sent as a WhatsApp text message', function () {
    $code = app(WhatsAppLinkService::class)->generateCode($this->student);

    $payload = json_encode(['from' => '+919876500040', 'type' => 'text', 'text' => ['body' => "LINK {$code}"]]);
    whatsappWebhookPost($payload, 'test-secret')->assertOk();

    $link = WhatsAppLink::where('phone_e164', '+919876500040')->sole();
    expect($link->user_id)->toBe($this->student->id)
        ->and($link->verified_at)->not->toBeNull();
});
