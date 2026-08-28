<?php

use App\Domains\AI\Livewire\DoubtChat;
use App\Domains\AI\Models\DoubtChatMessage;
use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Models\LessonArtifactChunk;
use App\Domains\AI\Services\DoubtChatService;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\FeatureFlag;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\AiSpendCapExceededException;
use App\Integrations\AiProvider;
use App\Integrations\ChatResult;
use App\Integrations\Drivers\FakeAiDriver;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

class RecordingChatDriver extends FakeAiDriver
{
    /** @var array<int, array{role: string, content: string}> */
    public static array $lastMessages = [];

    public function chat(array $messages, ?array $responseSchema = null): ChatResult
    {
        static::$lastMessages = $messages;

        return parent::chat($messages, $responseSchema);
    }
}

function abortStatus(callable $fn): int
{
    try {
        $fn();
    } catch (HttpExceptionInterface $e) {
        return $e->getStatusCode();
    }

    throw new RuntimeException('Expected an HTTP exception, none thrown.');
}

/**
 * scopeNearestNeighbors filters ->whereNotNull('embedding'), so a chunk
 * created without one is invisible to retrieval regardless of scoping —
 * every chunk in the isolation test below needs a real (any) vector.
 */
function chunkWithEmbedding(int $lessonArtifactId, int $chunkIdx, string $text): LessonArtifactChunk
{
    return LessonArtifactChunk::create([
        'lesson_artifact_id' => $lessonArtifactId,
        'chunk_idx' => $chunkIdx,
        'start_s' => 0,
        'end_s' => 5,
        'text' => $text,
        'embedding' => app(AiProvider::class)->embed($text),
    ]);
}

it('answers only from the asking lesson\'s latest-ready artifact, never a stale, cross-lesson, or cross-tenant one', function () {
    app()->bind(AiProvider::class, RecordingChatDriver::class);

    [$tenant, $section, $lesson] = gateFixture();
    $student = gateStaff($tenant, 'student');

    $readyArtifact = LessonArtifact::create(['lesson_id' => $lesson->id, 'version' => 1, 'source_asset_version' => 'v1', 'status' => 'ready']);
    chunkWithEmbedding($readyArtifact->id, 0, 'TARGET_CHUNK_TEXT_ALPHA');

    // Stale (non-ready) newer version of the SAME lesson — must never surface.
    $staleArtifact = LessonArtifact::create(['lesson_id' => $lesson->id, 'version' => 2, 'source_asset_version' => 'v2', 'status' => 'processing']);
    chunkWithEmbedding($staleArtifact->id, 0, 'DECOY_STALE_TEXT');

    // Cross-lesson decoy, same tenant.
    $otherSection = CourseSection::create(['course_id' => $section->course_id, 'title' => 'Other', 'position' => 1]);
    $otherLesson = Lesson::create(['course_section_id' => $otherSection->id, 'title' => 'Other', 'type' => 'video', 'position' => 0, 'video_status' => 'ready']);
    $otherArtifact = LessonArtifact::create(['lesson_id' => $otherLesson->id, 'version' => 1, 'source_asset_version' => 'v1', 'status' => 'ready']);
    chunkWithEmbedding($otherArtifact->id, 0, 'DECOY_CROSS_LESSON_TEXT');

    // Cross-tenant decoy.
    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    app(CurrentTenant::class)->set($otherTenant);
    $otherTenantCourse = Course::create(['title' => 'Chem', 'slug' => 'chem', 'status' => 'published']);
    $otherTenantSection = CourseSection::create(['course_id' => $otherTenantCourse->id, 'title' => 'S', 'position' => 0]);
    $otherTenantLesson = Lesson::create(['course_section_id' => $otherTenantSection->id, 'title' => 'L', 'type' => 'video', 'position' => 0, 'video_status' => 'ready']);
    $otherTenantArtifact = LessonArtifact::create(['lesson_id' => $otherTenantLesson->id, 'version' => 1, 'source_asset_version' => 'v1', 'status' => 'ready']);
    chunkWithEmbedding($otherTenantArtifact->id, 0, 'DECOY_CROSS_TENANT_TEXT');

    app(CurrentTenant::class)->set($tenant);

    $message = app(DoubtChatService::class)->ask($lesson, $student, 'What is velocity?');

    expect(RecordingChatDriver::$lastMessages[1]['content'])
        ->toContain('TARGET_CHUNK_TEXT_ALPHA')
        ->not->toContain('DECOY_STALE_TEXT')
        ->not->toContain('DECOY_CROSS_LESSON_TEXT')
        ->not->toContain('DECOY_CROSS_TENANT_TEXT');

    expect($message->cited_chunk_idxs)->toBe([0]);
});

it('returns a still-processing message, not an exception, when no ready artifact exists yet', function () {
    [$tenant, , $lesson] = gateFixture();
    $student = gateStaff($tenant, 'student');

    $message = app(DoubtChatService::class)->ask($lesson, $student, 'What is velocity?');

    expect($message->answer)->toContain('still processing')
        ->and($message->cited_chunk_idxs)->toBe([]);
});

it('gives the same 404 the stream route gives for a draft-course lesson', function () {
    [$tenant, , $lesson] = gateFixture(['status' => 'draft']);
    $student = gateStaff($tenant, 'student');

    expect(abortStatus(fn () => app(DoubtChatService::class)->ask($lesson, $student, 'Q?')))->toBe(404);
});

it('gives the same 404 the stream route gives for a future-scheduled lesson', function () {
    [$tenant, , $lesson] = gateFixture([], ['scheduled_at' => now()->addDay()]);
    $student = gateStaff($tenant, 'student');

    expect(abortStatus(fn () => app(DoubtChatService::class)->ask($lesson, $student, 'Q?')))->toBe(404);
});

it('gives the same 403 the stream route gives to a non-stream role on a non-preview lesson', function () {
    [$tenant, , $lesson] = gateFixture();
    $parent = gateStaff($tenant, 'parent');

    expect(abortStatus(fn () => app(DoubtChatService::class)->ask($lesson, $parent, 'Q?')))->toBe(403);
});

it('refuses to ask once the tenant is over its daily AI spend cap (S9 B12)', function () {
    // Not abortStatus() (built for abort()'s own HttpExceptionInterface) —
    // AiSpendCapExceededException is a plain RuntimeException only mapped
    // to a 409 by Laravel's exception *handler* during a real HTTP
    // request cycle (bootstrap/app.php's own render() registration,
    // exercised end-to-end for BandwidthQuotaExceededException in
    // BandwidthMeteringTest.php); calling the service directly here
    // bypasses that pipeline entirely, so the exception itself is what's
    // asserted, not a status code.
    [$tenant, , $lesson] = gateFixture();
    $student = gateStaff($tenant, 'student');

    LessonArtifact::create([
        'lesson_id' => $lesson->id,
        'version' => 1,
        'source_asset_version' => 'v1',
        'status' => 'ready',
        'token_cost' => ['in' => 1, 'out' => 1, 'usd' => 999.0],
    ]);

    expect(fn () => app(DoubtChatService::class)->ask($lesson, $student, 'Q?'))
        ->toThrow(AiSpendCapExceededException::class);
});

it('renders the doubt chat component and lets an authenticated student ask a question when the flag is on', function () {
    [$tenant, , $lesson] = gateFixture();
    $student = gateStaff($tenant, 'student');
    FeatureFlag::create(['tenant_id' => $tenant->id, 'key' => 'doubt_chat', 'enabled' => true]);

    $this->actingAs($student);

    Livewire::test(DoubtChat::class, ['lesson' => $lesson])
        ->set('question', 'What is velocity?')
        ->call('ask')
        ->assertHasNoErrors();

    expect(DoubtChatMessage::where('lesson_id', $lesson->id)->where('user_id', $student->id)->count())->toBe(1);
});

it('does not let a guest ask a question', function () {
    [$tenant, , $lesson] = gateFixture([], ['is_free_preview' => true]);
    FeatureFlag::create(['tenant_id' => $tenant->id, 'key' => 'doubt_chat', 'enabled' => true]);

    Livewire::test(DoubtChat::class, ['lesson' => $lesson])
        ->set('question', 'What is velocity?')
        ->call('ask');

    expect(DoubtChatMessage::count())->toBe(0);
});

it('blocks asking when the doubt_chat flag is unset for the tenant (opt-in default)', function () {
    [$tenant, , $lesson] = gateFixture();
    $student = gateStaff($tenant, 'student');

    $this->actingAs($student);

    Livewire::test(DoubtChat::class, ['lesson' => $lesson])
        ->set('question', 'What is velocity?')
        ->call('ask');

    expect(DoubtChatMessage::count())->toBe(0);
});

it('blocks asking when the doubt_chat flag is explicitly false for the tenant', function () {
    [$tenant, , $lesson] = gateFixture();
    $student = gateStaff($tenant, 'student');
    FeatureFlag::create(['tenant_id' => $tenant->id, 'key' => 'doubt_chat', 'enabled' => false]);

    $this->actingAs($student);

    Livewire::test(DoubtChat::class, ['lesson' => $lesson])
        ->set('question', 'What is velocity?')
        ->call('ask');

    expect(DoubtChatMessage::count())->toBe(0);
});
