<?php

use App\Domains\AI\Models\CourseOutlineDraft;
use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Services\CourseOutlineGenerator;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\AiSpendCapExceededException;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
    $this->creator = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

it('generates a well-formed draft from a topic', function () {
    $draft = app(CourseOutlineGenerator::class)->generate('Newtonian Mechanics', 'beginner', $this->creator);

    expect($draft)->toBeInstanceOf(CourseOutlineDraft::class)
        ->and($draft->tenant_id)->toBe($this->tenant->id)
        ->and($draft->created_by_user_id)->toBe($this->creator->id)
        ->and($draft->topic)->toBe('Newtonian Mechanics')
        ->and($draft->target_level)->toBe('beginner')
        ->and($draft->status)->toBe('draft')
        ->and($draft->created_course_id)->toBeNull()
        ->and($draft->generated_outline)->not->toBeEmpty()
        ->and($draft->token_cost)->toHaveKeys(['in', 'out', 'usd']);

    foreach ($draft->generated_outline as $section) {
        expect($section)->toHaveKeys(['section_title', 'lessons'])
            ->and($section['lessons'])->not->toBeEmpty();

        foreach ($section['lessons'] as $lesson) {
            expect($lesson)->toHaveKeys(['title', 'description']);
        }
    }
});

it('generates a draft with a null target_level when none is given', function () {
    $draft = app(CourseOutlineGenerator::class)->generate('Thermodynamics', null, $this->creator);

    expect($draft->target_level)->toBeNull();
});

it('lets an AiSpendCapExceededException bubble up uncaught, same as every other AiProvider call site', function () {
    // No new spend-cap code in CourseOutlineGenerator — it just calls
    // AiProvider::chat(), already gated by SpendCapGuardedAiDriver at the
    // container-binding level (same shape DoubtChatTest's own equivalent
    // test already proves for DoubtChatService::ask()).
    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid()]);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create([
        'course_section_id' => $section->id,
        'title' => 'Velocity',
        'type' => 'video',
        'position' => 0,
    ]);
    LessonArtifact::create([
        'lesson_id' => $lesson->id,
        'version' => 1,
        'source_asset_version' => 'v1',
        'status' => 'ready',
        'token_cost' => ['in' => 1, 'out' => 1, 'usd' => 999.0],
    ]);

    expect(fn () => app(CourseOutlineGenerator::class)->generate('Topic', null, $this->creator))
        ->toThrow(AiSpendCapExceededException::class);
});
