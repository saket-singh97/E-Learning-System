<?php

use App\Domains\AI\Models\CourseOutlineDraft;
use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Services\LessonPlanAssistant;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\AiProvider;
use App\Integrations\ChatResult;
use App\Integrations\Drivers\FakeAiDriver;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

class RecordingLessonPlanDriver extends FakeAiDriver
{
    /** @var array<int, array{role: string, content: string}> */
    public static array $lastMessages = [];

    public function chat(array $messages, ?array $responseSchema = null): ChatResult
    {
        static::$lastMessages = $messages;

        return parent::chat($messages, $responseSchema);
    }
}

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
    $this->creator = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid()]);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Thermodynamics', 'position' => 0]);
    $this->lesson = Lesson::create([
        'course_section_id' => $section->id,
        'title' => 'Laws of Thermodynamics',
        'type' => 'video',
        'position' => 0,
    ]);
});

it('drafts a lesson plan grounded in the preceding lessons real syllabus_map', function () {
    app()->bind(AiProvider::class, RecordingLessonPlanDriver::class);

    LessonArtifact::create([
        'lesson_id' => $this->lesson->id,
        'version' => 1,
        'source_asset_version' => 'v1',
        'status' => 'ready',
        'syllabus_map' => ['board' => 'CBSE', 'subject' => 'Physics', 'chapter' => 'Thermodynamics', 'topic' => 'Entropy'],
    ]);

    $draft = app(LessonPlanAssistant::class)->generateFor($this->lesson, $this->creator);

    expect($draft)->toBeInstanceOf(CourseOutlineDraft::class)
        ->and($draft->created_by_user_id)->toBe($this->creator->id)
        ->and($draft->status)->toBe('draft')
        ->and($draft->generated_outline)->not->toBeEmpty()
        ->and($draft->token_cost)->toHaveKeys(['in', 'out', 'usd']);

    expect(RecordingLessonPlanDriver::$lastMessages[0]['content'])
        ->toContain('Entropy')
        ->toContain('Thermodynamics');
});

it('returns null, not an exception, when the preceding lesson has no ready syllabus_map yet', function () {
    expect(app(LessonPlanAssistant::class)->generateFor($this->lesson, $this->creator))->toBeNull();

    expect(CourseOutlineDraft::count())->toBe(0);
});

it('returns null when the only artifact has not finished ingesting', function () {
    LessonArtifact::create([
        'lesson_id' => $this->lesson->id,
        'version' => 1,
        'source_asset_version' => 'v1',
        'status' => 'processing',
        'syllabus_map' => ['board' => 'CBSE', 'subject' => 'Physics', 'chapter' => 'Thermodynamics', 'topic' => 'Entropy'],
    ]);

    expect(app(LessonPlanAssistant::class)->generateFor($this->lesson, $this->creator))->toBeNull();
});
