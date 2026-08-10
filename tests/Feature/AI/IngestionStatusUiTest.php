<?php

use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Models\LessonArtifactChunk;
use App\Domains\Content\Events\LessonVideoReady;
use App\Domains\Content\Filament\Resources\CourseSections\CourseSectionResource;
use App\Domains\Content\Filament\Resources\CourseSections\Pages\EditCourseSection;
use App\Domains\Content\Filament\Resources\CourseSections\RelationManagers\LessonsRelationManager;
use App\Domains\Content\Filament\Resources\Lessons\Pages\ViewLessonArtifact;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\AiProvider;
use App\Integrations\Drivers\FakeAiDriver;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->instructor->assignRole('instructor');

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid()]);
    $this->section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);

    $this->actingAs($this->instructor)
        ->get(CourseSectionResource::getUrl('edit', ['record' => $this->section, 'subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();
});

it('shows the AI status badge as ready once ingestion completes after upload', function () {
    Livewire::test(LessonsRelationManager::class, [
        'ownerRecord' => $this->section,
        'pageClass' => EditCourseSection::class,
    ])
        ->callTableAction('create', data: [
            'title' => 'Velocity',
            'type' => 'video',
            'video_upload' => UploadedFile::fake()->create('lecture.mp4', 100, 'video/mp4'),
        ])
        ->assertHasNoTableActionErrors();

    $lesson = Lesson::sole();

    Livewire::test(LessonsRelationManager::class, [
        'ownerRecord' => $this->section,
        'pageClass' => EditCourseSection::class,
    ])->assertTableColumnStateSet('ai_status', 'ready', $lesson);
});

it('shows only the latest-ready artifact content, never a processing or failed one', function () {
    $lesson = Lesson::create([
        'course_section_id' => $this->section->id,
        'title' => 'Velocity',
        'type' => 'video',
        'position' => 0,
    ]);

    LessonArtifact::create([
        'lesson_id' => $lesson->id,
        'version' => 1,
        'source_asset_version' => 'v1',
        'status' => 'ready',
        'transcript' => 'Ready transcript v1',
        'concepts' => [['name' => 'Kinematics', 'confidence' => 0.9, 'chunk_idxs' => [0]]],
    ]);

    LessonArtifact::create([
        'lesson_id' => $lesson->id,
        'version' => 2,
        'source_asset_version' => 'v2',
        'status' => 'processing',
    ]);

    Livewire::test(ViewLessonArtifact::class, ['record' => $lesson->id])
        ->assertSee('Ready transcript v1')
        ->assertSee('Kinematics');
});

it('retries a failed ingestion back to ready, at the same version, via the retry action', function () {
    app()->bind(AiProvider::class, ThrowingChatDriver::class);

    $lesson = Lesson::create([
        'course_section_id' => $this->section->id,
        'title' => 'Velocity',
        'type' => 'video',
        'position' => 0,
        'video_disk_path' => "videos/{$this->tenant->id}/lecture.mp4",
    ]);

    event(new LessonVideoReady($lesson->id, $this->tenant->id));

    $artifact = LessonArtifact::where('lesson_id', $lesson->id)->sole();
    expect($artifact->status)->toBe('failed');

    // ThrowingChatDriver fails at ExtractLessonConcepts, i.e. after
    // ChunkTranscript already succeeded — chunk rows exist from this first,
    // failed attempt.
    $chunkCountAfterFailure = LessonArtifactChunk::where('lesson_artifact_id', $artifact->id)->count();
    expect($chunkCountAfterFailure)->toBeGreaterThan(0);

    Livewire::test(LessonsRelationManager::class, [
        'ownerRecord' => $this->section,
        'pageClass' => EditCourseSection::class,
    ])->assertTableActionVisible('retryIngestion', $lesson);

    app()->bind(AiProvider::class, FakeAiDriver::class);

    Livewire::test(LessonsRelationManager::class, [
        'ownerRecord' => $this->section,
        'pageClass' => EditCourseSection::class,
    ])->callTableAction('retryIngestion', $lesson);

    $artifact->refresh();
    expect($artifact->status)->toBe('ready')
        ->and($artifact->version)->toBe(1);

    // Regression: retry re-runs the whole chain from TranscribeLesson, so
    // ChunkTranscript must replace, not append to, the first attempt's
    // chunk rows — otherwise a retry after a late-stage failure silently
    // duplicates every chunk for this lesson_artifact_id.
    expect(LessonArtifactChunk::where('lesson_artifact_id', $artifact->id)->count())
        ->toBe($chunkCountAfterFailure);

    Livewire::test(LessonsRelationManager::class, [
        'ownerRecord' => $this->section,
        'pageClass' => EditCourseSection::class,
    ])->assertTableActionHidden('retryIngestion', $lesson);
});
