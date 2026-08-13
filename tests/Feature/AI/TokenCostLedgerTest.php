<?php

use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Services\TokenCostLedger;
use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Services\QuestionGenerator;
use App\Domains\Content\Events\LessonVideoReady;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Filament\Widgets\TokenSpendWidget;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('sums token_cost correctly across multiple lessons ingested the same day', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $lessonA = ingestibleLesson($tenant);
    $lessonB = ingestibleLesson($tenant);

    event(new LessonVideoReady($lessonA->id, $tenant->id));
    event(new LessonVideoReady($lessonB->id, $tenant->id));

    $artifacts = LessonArtifact::whereIn('lesson_id', [$lessonA->id, $lessonB->id])->get();
    expect($artifacts)->toHaveCount(2)
        ->and($artifacts->pluck('status')->unique()->all())->toBe(['ready']);

    $expectedIn = $artifacts->sum(fn ($a) => $a->token_cost['in']);
    $expectedOut = $artifacts->sum(fn ($a) => $a->token_cost['out']);
    $expectedUsd = round($artifacts->sum(fn ($a) => $a->token_cost['usd']), 6);

    $total = app(TokenCostLedger::class)->totalForDate(now());

    expect($total)->toBe([
        'in' => $expectedIn,
        'out' => $expectedOut,
        'usd' => $expectedUsd,
        'count' => 2,
    ]);
});

it('folds question-generation cost into the total, but not into the ingestion-only count (S9 B14)', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid(), 'status' => 'published']);

    app(QuestionGenerator::class)->generate($course, [
        ['concept' => 'Motion', 'count' => 2],
    ]);

    $questions = Question::where('course_id', $course->id)->get();
    expect($questions)->toHaveCount(2);

    $expectedUsd = round($questions->sum(fn (Question $q) => $q->token_cost['usd']), 6);
    expect($expectedUsd)->toBeGreaterThan(0);

    $total = app(TokenCostLedger::class)->totalForDate(now());

    expect($total['usd'])->toBe($expectedUsd)
        ->and($total['in'])->toBe((int) $questions->sum(fn (Question $q) => $q->token_cost['in']))
        // Ingestion-only, per the ledger's own documented convention —
        // question generation contributes to spend but not to this count.
        ->and($total['count'])->toBe(0);
});

it('scopes to the current tenant when one is bound, and sums platform-wide when none is', function () {
    $tenantA = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenantA);
    event(new LessonVideoReady(ingestibleLesson($tenantA)->id, $tenantA->id));

    $tenantB = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenantB);
    event(new LessonVideoReady(ingestibleLesson($tenantB)->id, $tenantB->id));

    app(CurrentTenant::class)->set($tenantA);
    expect(app(TokenCostLedger::class)->totalForDate(now())['count'])->toBe(1);

    app(CurrentTenant::class)->set($tenantB);
    expect(app(TokenCostLedger::class)->totalForDate(now())['count'])->toBe(1);

    app(CurrentTenant::class)->clear();
    expect(app(TokenCostLedger::class)->totalForDate(now())['count'])->toBe(2);
});

it('renders the shared spend widget without error', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    event(new LessonVideoReady(ingestibleLesson($tenant)->id, $tenant->id));

    Livewire::test(TokenSpendWidget::class)->assertSee('AI spend today');
});
