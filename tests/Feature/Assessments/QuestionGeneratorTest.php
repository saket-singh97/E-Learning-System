<?php

use App\Domains\AI\Models\LessonArtifact;
use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Services\QuestionGenerator;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\AiProvider;
use App\Integrations\ChatResult;
use App\Integrations\Drivers\FakeAiDriver;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Returns a fixed numerical-shaped seed_questions payload regardless of the
 * prompt — FakeAiDriver's own seed_questions fake always hardcodes
 * type=mcq, so correctAnswerFromSeed()'s numerical branch (a real vendor
 * behaviour, not a fake-driver one) needs its own double to ever run.
 */
class NumericalSeedDriver extends FakeAiDriver
{
    public function chat(array $messages, ?array $responseSchema = null): ChatResult
    {
        return new ChatResult(structured: [
            'seed_questions' => [[
                'type' => 'numerical',
                'stem' => 'What is g, in m/s^2?',
                'answer' => ['value' => 9.8, 'tolerance' => 0.2],
                'explanation' => 'Standard gravity.',
                'concept' => 'Gravity',
                'difficulty' => 'medium',
            ]],
        ], usage: ['in' => 1, 'out' => 1, 'usd' => 0.0]);
    }
}

/** Simulates a vendor whose structured call comes back empty — no crash, no partial row. */
class EmptySeedDriver extends FakeAiDriver
{
    public function chat(array $messages, ?array $responseSchema = null): ChatResult
    {
        return new ChatResult(structured: ['seed_questions' => []], usage: ['in' => 1, 'out' => 1, 'usd' => 0.0]);
    }
}

beforeEach(function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $this->course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid()]);
    $this->section = CourseSection::create(['course_id' => $this->course->id, 'title' => 'Kinematics', 'position' => 0]);
    $this->lesson = Lesson::create([
        'course_section_id' => $this->section->id,
        'title' => 'Velocity',
        'type' => 'video',
        'position' => 0,
    ]);
});

it('promotes a ready lessons seed_questions into draft, ai_generated Question rows', function () {
    LessonArtifact::create([
        'lesson_id' => $this->lesson->id,
        'version' => 1,
        'source_asset_version' => 'v1',
        'status' => 'ready',
        'seed_questions' => [[
            'type' => 'mcq',
            'stem' => 'What is velocity?',
            'options' => ['Speed', 'Speed with direction'],
            'answer' => 'Speed with direction',
            'explanation' => 'Velocity is a vector.',
            'concept' => 'Kinematics',
            'difficulty' => 'easy',
        ]],
    ]);

    $created = app(QuestionGenerator::class)->fromSeedQuestions($this->lesson);

    expect($created)->toHaveCount(1);

    $question = Question::sole();

    expect($question->course_id)->toBe($this->course->id)
        ->and($question->lesson_id)->toBe($this->lesson->id)
        ->and($question->type)->toBe('mcq')
        ->and($question->stem)->toBe('What is velocity?')
        ->and($question->options)->toBe(['Speed', 'Speed with direction'])
        ->and($question->correct_answer)->toBe('Speed with direction')
        ->and($question->status)->toBe('draft')
        ->and($question->source)->toBe('ai_generated')
        // No AiProvider::chat() call happened on this path — the seed was
        // already extracted during ingestion — so there's no real cost to
        // record (S9 B14).
        ->and($question->token_cost)->toBeNull();
});

it('no-ops instead of erroring for a lesson with no ready artifact', function () {
    $created = app(QuestionGenerator::class)->fromSeedQuestions($this->lesson);

    expect($created)->toBeEmpty()
        ->and(Question::count())->toBe(0);
});

it('no-ops for a lesson whose only artifact has not finished ingesting', function () {
    LessonArtifact::create([
        'lesson_id' => $this->lesson->id,
        'version' => 1,
        'source_asset_version' => 'v1',
        'status' => 'processing',
        'seed_questions' => null,
    ]);

    $created = app(QuestionGenerator::class)->fromSeedQuestions($this->lesson);

    expect($created)->toBeEmpty();
});

it('generates exactly the requested count per blueprint line', function () {
    $created = app(QuestionGenerator::class)->generate($this->course, [
        ['concept' => 'Motion', 'difficulty' => 'easy', 'type' => 'mcq', 'count' => 3],
        ['concept' => 'Forces', 'difficulty' => 'hard', 'type' => 'mcq', 'count' => 2],
    ]);

    expect($created)->toHaveCount(5)
        ->and(Question::where('status', 'draft')->where('source', 'ai_generated')->count())->toBe(5)
        ->and(Question::where('course_id', $this->course->id)->count())->toBe(5);
});

it('records each real chat() calls own usage as the generated questions token_cost', function () {
    // S9 B14 review pass: generate() discarded ChatResult::$usage on every
    // real AiProvider::chat() call entirely — TokenCostLedger (and by
    // extension B12's own AI spend cap) never saw a single one of these
    // questions' real cost despite the spend-cap decorator gating every
    // call that produced them.
    $created = app(QuestionGenerator::class)->generate($this->course, [
        ['concept' => 'Motion', 'count' => 2],
    ]);

    $created->each(function (Question $question) {
        expect($question->token_cost)->not->toBeNull()
            ->and($question->token_cost)->toHaveKeys(['in', 'out', 'usd']);
    });
});

it('varies the generated stem across multiple questions in the same blueprint line', function () {
    $created = app(QuestionGenerator::class)->generate($this->course, [
        ['concept' => 'Motion', 'count' => 3],
    ]);

    expect($created->pluck('stem')->unique())->toHaveCount(3);
});

it('normalises a structured numerical answer into {value, tolerance}', function () {
    app()->bind(AiProvider::class, NumericalSeedDriver::class);

    $created = app(QuestionGenerator::class)->generate($this->course, [
        ['concept' => 'Gravity', 'type' => 'numerical', 'count' => 1],
    ]);

    expect($created)->toHaveCount(1);
    expect($created->first()->correct_answer)->toBe(['value' => 9.8, 'tolerance' => 0.2]);
});

it('skips silently when the vendor returns no seed question for a call', function () {
    app()->bind(AiProvider::class, EmptySeedDriver::class);

    $created = app(QuestionGenerator::class)->generate($this->course, [
        ['concept' => 'Motion', 'count' => 2],
    ]);

    expect($created)->toBeEmpty()
        ->and(Question::count())->toBe(0);
});
