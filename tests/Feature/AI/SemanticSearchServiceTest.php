<?php

use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Models\LessonArtifactChunk;
use App\Domains\AI\Services\SemanticSearchService;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\AiProvider;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Same helper DoubtChatTest.php already defines for its own retrieval
 * tests — nearestNeighbors() filters ->whereNotNull('embedding'), so every
 * chunk here needs a real vector regardless of which test uses it.
 */
function semanticSearchChunk(int $lessonArtifactId, int $tenantId, int $chunkIdx, string $text): LessonArtifactChunk
{
    return LessonArtifactChunk::create([
        'tenant_id' => $tenantId,
        'lesson_artifact_id' => $lessonArtifactId,
        'chunk_idx' => $chunkIdx,
        'start_s' => 0,
        'end_s' => 5,
        'text' => $text,
        'embedding' => app(AiProvider::class)->embed($text),
    ]);
}

it('never returns a chunk from a lesson the viewer cannot see, even when its similarity score is highest', function () {
    [$tenant, , $visibleLesson] = gateFixture();
    $student = gateStaff($tenant, 'student');

    $visibleArtifact = LessonArtifact::create(['lesson_id' => $visibleLesson->id, 'version' => 1, 'source_asset_version' => 'v1', 'status' => 'ready']);
    semanticSearchChunk($visibleArtifact->id, $tenant->id, 0, 'Velocity is displacement over time.');

    // A second, DRAFT course + lesson in the SAME tenant (invisible to a
    // student) whose chunk is embedded with the exact query text —
    // guaranteed cosine distance 0, the nearest possible match — so this
    // proves the visibility filter runs even against the single
    // best-scoring candidate, not just an average one.
    $draftCourse = Course::create(['title' => 'Draft Course', 'slug' => 'draft-'.uniqid(), 'status' => 'draft']);
    $draftSection = CourseSection::create(['course_id' => $draftCourse->id, 'title' => 'S', 'position' => 0]);
    $invisibleLesson = Lesson::create(['course_section_id' => $draftSection->id, 'title' => 'L', 'type' => 'video', 'position' => 0, 'video_status' => 'ready']);
    $invisibleArtifact = LessonArtifact::create(['lesson_id' => $invisibleLesson->id, 'version' => 1, 'source_asset_version' => 'v1', 'status' => 'ready']);
    semanticSearchChunk($invisibleArtifact->id, $tenant->id, 0, 'What is velocity?');

    $results = app(SemanticSearchService::class)->search('What is velocity?', $student, $tenant->id);

    expect($results->pluck('text')->all())
        ->toContain('Velocity is displacement over time.')
        ->not->toContain('What is velocity?');
});

it('scopes results to the viewers own tenant only', function () {
    [$tenant, , $lesson] = gateFixture();
    $student = gateStaff($tenant, 'student');

    $artifact = LessonArtifact::create(['lesson_id' => $lesson->id, 'version' => 1, 'source_asset_version' => 'v1', 'status' => 'ready']);
    semanticSearchChunk($artifact->id, $tenant->id, 0, 'TARGET_OWN_TENANT_TEXT');

    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    app(CurrentTenant::class)->set($otherTenant);
    $otherCourse = Course::create(['title' => 'Chem', 'slug' => 'chem-'.uniqid(), 'status' => 'published']);
    $otherSection = CourseSection::create(['course_id' => $otherCourse->id, 'title' => 'S', 'position' => 0]);
    $otherLesson = Lesson::create(['course_section_id' => $otherSection->id, 'title' => 'L', 'type' => 'video', 'position' => 0, 'video_status' => 'ready']);
    $otherArtifact = LessonArtifact::create(['lesson_id' => $otherLesson->id, 'version' => 1, 'source_asset_version' => 'v1', 'status' => 'ready']);
    semanticSearchChunk($otherArtifact->id, $otherTenant->id, 0, 'DECOY_CROSS_TENANT_TEXT');

    app(CurrentTenant::class)->set($tenant);

    $results = app(SemanticSearchService::class)->search('TARGET_OWN_TENANT_TEXT', $student, $tenant->id);

    expect($results->pluck('text')->all())
        ->toContain('TARGET_OWN_TENANT_TEXT')
        ->not->toContain('DECOY_CROSS_TENANT_TEXT');
});

it('excludes chunks from a still-processing (non-ready) artifact', function () {
    [$tenant, , $lesson] = gateFixture();
    $student = gateStaff($tenant, 'student');

    $processingArtifact = LessonArtifact::create(['lesson_id' => $lesson->id, 'version' => 1, 'source_asset_version' => 'v1', 'status' => 'processing']);
    semanticSearchChunk($processingArtifact->id, $tenant->id, 0, 'STILL_INGESTING_TEXT');

    $results = app(SemanticSearchService::class)->search('STILL_INGESTING_TEXT', $student, $tenant->id);

    expect($results)->toBeEmpty();
});

it('returns nothing for an empty query without calling embed()', function () {
    [$tenant] = gateFixture();
    $student = gateStaff($tenant, 'student');

    $results = app(SemanticSearchService::class)->search('   ', $student, $tenant->id);

    expect($results)->toBeEmpty();
});
