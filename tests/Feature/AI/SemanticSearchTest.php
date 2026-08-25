<?php

use App\Domains\AI\Livewire\SemanticSearch;
use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Models\LessonArtifactChunk;
use App\Integrations\AiProvider;
use App\Integrations\Drivers\FakeAiDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

class RecordingEmbedDriver extends FakeAiDriver
{
    public static int $embedCalls = 0;

    public function embed(string $text): array
    {
        static::$embedCalls++;

        return parent::embed($text);
    }
}

beforeEach(function () {
    RecordingEmbedDriver::$embedCalls = 0;
});

it('calls no AiProvider::embed() at all for an empty query', function () {
    app()->bind(AiProvider::class, RecordingEmbedDriver::class);

    [$tenant] = gateFixture();
    $student = gateStaff($tenant, 'student');
    $this->actingAs($student);

    Livewire::test(SemanticSearch::class)
        ->set('query', '   ')
        ->assertSet('results', collect());

    expect(RecordingEmbedDriver::$embedCalls)->toBe(0);
});

it('returns results a logged-in student can click through to the right lesson timestamp', function () {
    [$tenant, , $lesson] = gateFixture();
    $student = gateStaff($tenant, 'student');
    $this->actingAs($student);

    $artifact = LessonArtifact::create(['lesson_id' => $lesson->id, 'version' => 1, 'source_asset_version' => 'v1', 'status' => 'ready']);
    LessonArtifactChunk::create([
        'lesson_artifact_id' => $artifact->id,
        'chunk_idx' => 0,
        'start_s' => 42,
        'end_s' => 47,
        'text' => 'Velocity is displacement over time.',
        'embedding' => app(AiProvider::class)->embed('Velocity is displacement over time.'),
    ]);

    $component = Livewire::test(SemanticSearch::class)
        ->set('query', 'Velocity is displacement over time.');

    expect($component->get('results')->pluck('text')->all())
        ->toContain('Velocity is displacement over time.');

    $chunk = $component->get('results')->first();

    expect($component->instance()->watchUrlFor($chunk))
        ->toContain("/lessons/{$lesson->id}")
        ->toContain('#t=42');
});

it('shows nothing for a guest instead of erroring', function () {
    [$tenant, , $lesson] = gateFixture();

    $artifact = LessonArtifact::create(['lesson_id' => $lesson->id, 'version' => 1, 'source_asset_version' => 'v1', 'status' => 'ready']);
    LessonArtifactChunk::create([
        'lesson_artifact_id' => $artifact->id,
        'chunk_idx' => 0,
        'start_s' => 0,
        'end_s' => 5,
        'text' => 'Velocity is displacement over time.',
        'embedding' => app(AiProvider::class)->embed('Velocity is displacement over time.'),
    ]);

    Livewire::test(SemanticSearch::class)
        ->set('query', 'Velocity')
        ->assertSet('results', collect());
});
