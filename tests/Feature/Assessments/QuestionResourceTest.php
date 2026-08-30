<?php

use App\Domains\Assessments\Filament\Resources\Questions\Pages\CreateQuestion;
use App\Domains\Assessments\Filament\Resources\Questions\Pages\EditQuestion;
use App\Domains\Assessments\Filament\Resources\Questions\QuestionResource;
use App\Domains\Assessments\Models\Question;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->instructor->assignRole('instructor');

    $this->course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);

    $this->actingAs($this->instructor)
        ->get(QuestionResource::getUrl('create', ['subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();
});

function decodeMatchingPairs(Question $question): array
{
    return collect($question->correct_answer['pairs'])
        ->mapWithKeys(fn (array $pair): array => [
            $question->options['left'][$pair[0]] => $question->options['right'][$pair[1]],
        ])
        ->all();
}

function decodeOrderingSequence(Question $question): array
{
    return collect($question->correct_answer['correct_sequence'])
        ->map(fn (int $index): string => $question->options[$index])
        ->all();
}

it('creates an mcq question as an unapproved draft', function () {
    Livewire::test(CreateQuestion::class)
        ->fillForm([
            'course_id' => $this->course->id,
            'type' => 'mcq',
            'stem' => 'What is 2 + 2?',
            'options' => ['3', '4', '5'],
            'correct_answer_mcq' => '4',
            'difficulty' => 'easy',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $question = Question::sole();

    expect($question->status)->toBe('draft')
        ->and($question->source)->toBe('manual')
        ->and($question->created_by)->toBe($this->instructor->id)
        ->and($question->options)->toBe(['3', '4', '5'])
        ->and($question->correct_answer)->toBe('4');
});

it('creates a true_false question with automatic options', function () {
    Livewire::test(CreateQuestion::class)
        ->fillForm([
            'course_id' => $this->course->id,
            'type' => 'true_false',
            'stem' => 'Newton wrote the Principia.',
            'correct_answer_true_false' => 'True',
            'difficulty' => 'medium',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $question = Question::sole();

    expect($question->options)->toBe(['True', 'False'])
        ->and($question->correct_answer)->toBe('True');
});

it('creates a short_answer question with no options and a model answer', function () {
    Livewire::test(CreateQuestion::class)
        ->fillForm([
            'course_id' => $this->course->id,
            'type' => 'short_answer',
            'stem' => 'State Newtons second law.',
            'correct_answer_short' => 'Force equals mass times acceleration.',
            'difficulty' => 'medium',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $question = Question::sole();

    expect($question->options)->toBeNull()
        ->and($question->correct_answer)->toBe('Force equals mass times acceleration.');
});

it('creates a numerical question with a value and tolerance', function () {
    Livewire::test(CreateQuestion::class)
        ->fillForm([
            'course_id' => $this->course->id,
            'type' => 'numerical',
            'stem' => 'Acceleration due to gravity, in m/s^2?',
            'correct_answer_numerical_value' => '9.8',
            'correct_answer_numerical_tolerance' => '0.2',
            'difficulty' => 'hard',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $question = Question::sole();

    expect($question->options)->toBeNull()
        ->and($question->correct_answer)->toBe(['value' => 9.8, 'tolerance' => 0.2]);
});

it('rejects an mcq question with fewer than two options', function () {
    Livewire::test(CreateQuestion::class)
        ->fillForm([
            'course_id' => $this->course->id,
            'type' => 'mcq',
            'stem' => 'Broken question',
            'options' => ['Only one'],
            'difficulty' => 'easy',
        ])
        ->call('create')
        ->assertHasFormErrors(['options']);

    expect(Question::count())->toBe(0);
});

// S16 B8 (I6 pt.2) — the 4 new types.

it('creates a multi_select question with the checked options as correct_option_ids', function () {
    Livewire::test(CreateQuestion::class)
        ->fillForm([
            'course_id' => $this->course->id,
            'type' => 'multi_select',
            'stem' => 'Which are prime numbers?',
            'options' => ['2', '3', '4', '5'],
            'correct_answer_multi_select' => ['2', '3', '5'],
            'difficulty' => 'medium',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $question = Question::sole();

    expect($question->options)->toBe(['2', '3', '4', '5'])
        ->and($question->correct_answer)->toBe(['correct_option_ids' => ['2', '3', '5']]);
});

it('creates a fill_in_blank question with a list of accepted answers', function () {
    Livewire::test(CreateQuestion::class)
        ->fillForm([
            'course_id' => $this->course->id,
            'type' => 'fill_in_blank',
            'stem' => 'Who formulated the laws of motion?',
            'correct_answer_fill_in_blank' => ['Newton', 'Sir Isaac Newton'],
            'difficulty' => 'easy',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $question = Question::sole();

    expect($question->options)->toBeNull()
        ->and($question->correct_answer)->toBe(['accepted_answers' => ['Newton', 'Sir Isaac Newton']]);
});

it('creates a matching question whose stored pairs reproduce the authored left/right text despite the right-side shuffle', function () {
    Livewire::test(CreateQuestion::class)
        ->fillForm([
            'course_id' => $this->course->id,
            'type' => 'matching',
            'stem' => 'Match the scientist to their discovery.',
            'matching_pairs' => [
                ['left' => 'Newton', 'right' => 'Gravity'],
                ['left' => 'Darwin', 'right' => 'Evolution'],
                ['left' => 'Curie', 'right' => 'Radioactivity'],
            ],
            'difficulty' => 'medium',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $question = Question::sole();

    expect($question->options['left'])->toBe(['Newton', 'Darwin', 'Curie']);
    expect($question->options['right'])->toEqualCanonicalizing(['Gravity', 'Evolution', 'Radioactivity']);

    $pairs = $question->correct_answer['pairs'];
    expect($pairs)->toHaveCount(3);

    foreach ($pairs as [$leftIndex, $rightIndex]) {
        $left = $question->options['left'][$leftIndex];
        $right = $question->options['right'][$rightIndex];

        $expected = match ($left) {
            'Newton' => 'Gravity',
            'Darwin' => 'Evolution',
            'Curie' => 'Radioactivity',
        };

        expect($right)->toBe($expected);
    }
});

it('creates an ordering question whose stored sequence reproduces the authored order despite the item shuffle', function () {
    Livewire::test(CreateQuestion::class)
        ->fillForm([
            'course_id' => $this->course->id,
            'type' => 'ordering',
            'stem' => 'Order the steps of the scientific method.',
            'ordering_items' => [
                ['label' => 'Observe'],
                ['label' => 'Hypothesize'],
                ['label' => 'Experiment'],
                ['label' => 'Conclude'],
            ],
            'difficulty' => 'medium',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $question = Question::sole();

    expect($question->options)->toEqualCanonicalizing(['Observe', 'Hypothesize', 'Experiment', 'Conclude']);

    $sequence = $question->correct_answer['correct_sequence'];
    expect($sequence)->toHaveCount(4);

    $orderedLabels = collect($sequence)->map(fn (int $index) => $question->options[$index])->all();
    expect($orderedLabels)->toBe(['Observe', 'Hypothesize', 'Experiment', 'Conclude']);
});

// Repeater fields (matching_pairs/ordering_items) key their live form
// state by an internal Filament-generated UUID per row, not a plain
// 0-indexed array — assertFormSet can't compare against a literal
// sequential array for them. Proven instead via a real edit-and-resave:
// an unrelated field changes, and the *logical* pairing/order must
// survive, even though the stored right-hand/item order re-shuffles on
// every save (normalizeQuestionFormData re-derives it fresh each time —
// a fresh shuffle each save, not a stable byte-for-byte one, so this
// checks the decoded meaning, not literal array equality).
it('preserves a matching questions pairing across an unrelated edit-and-resave', function () {
    Livewire::test(CreateQuestion::class)
        ->fillForm([
            'course_id' => $this->course->id,
            'type' => 'matching',
            'stem' => 'Match capitals to countries.',
            'matching_pairs' => [
                ['left' => 'France', 'right' => 'Paris'],
                ['left' => 'Japan', 'right' => 'Tokyo'],
            ],
            'difficulty' => 'easy',
        ])
        ->call('create');

    $question = Question::sole();

    Livewire::test(EditQuestion::class, ['record' => $question->id])
        ->fillForm(['stem' => 'Match capitals to countries (updated).'])
        ->call('save')
        ->assertHasNoFormErrors();

    $question->refresh();

    expect($question->stem)->toBe('Match capitals to countries (updated).');
    expect(decodeMatchingPairs($question))->toEqualCanonicalizing([
        'France' => 'Paris',
        'Japan' => 'Tokyo',
    ]);
});

it('preserves an ordering questions correct order across an unrelated edit-and-resave', function () {
    Livewire::test(CreateQuestion::class)
        ->fillForm([
            'course_id' => $this->course->id,
            'type' => 'ordering',
            'stem' => 'Order the planets from the sun.',
            'ordering_items' => [
                ['label' => 'Mercury'],
                ['label' => 'Venus'],
                ['label' => 'Earth'],
            ],
            'difficulty' => 'easy',
        ])
        ->call('create');

    $question = Question::sole();

    Livewire::test(EditQuestion::class, ['record' => $question->id])
        ->fillForm(['stem' => 'Order the planets from the sun (updated).'])
        ->call('save')
        ->assertHasNoFormErrors();

    $question->refresh();

    expect($question->stem)->toBe('Order the planets from the sun (updated).');
    expect(decodeOrderingSequence($question))->toBe(['Mercury', 'Venus', 'Earth']);
});

it('edits a draft question without touching its status, and prefills the correct answer for its type', function () {
    $question = Question::create([
        'course_id' => $this->course->id,
        'type' => 'mcq',
        'stem' => 'Original stem',
        'options' => ['A', 'B'],
        'correct_answer' => 'B',
        'difficulty' => 'easy',
        'status' => 'draft',
        'source' => 'manual',
    ]);

    Livewire::test(EditQuestion::class, ['record' => $question->id])
        ->assertFormSet(['correct_answer_mcq' => 'B'])
        ->fillForm(['stem' => 'Updated stem'])
        ->call('save')
        ->assertHasNoFormErrors();

    $question->refresh();

    expect($question->stem)->toBe('Updated stem')
        ->and($question->status)->toBe('draft');
});

it('creates a question with the PYQ fields omitted, leaving them false/null', function () {
    Livewire::test(CreateQuestion::class)
        ->fillForm([
            'course_id' => $this->course->id,
            'type' => 'mcq',
            'stem' => 'What is 2 + 2?',
            'options' => ['3', '4', '5'],
            'correct_answer_mcq' => '4',
            'difficulty' => 'easy',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $question = Question::sole();

    expect($question->is_pyq)->toBeFalse()
        ->and($question->pyq_exam_name)->toBeNull()
        ->and($question->pyq_exam_year)->toBeNull();
});

it('tags a question as a previous-year question with exam name and year', function () {
    Livewire::test(CreateQuestion::class)
        ->fillForm([
            'course_id' => $this->course->id,
            'type' => 'mcq',
            'stem' => 'What is 2 + 2?',
            'options' => ['3', '4', '5'],
            'correct_answer_mcq' => '4',
            'difficulty' => 'easy',
            'is_pyq' => true,
            'pyq_exam_name' => 'JEE Main',
            'pyq_exam_year' => 2024,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $question = Question::sole();

    expect($question->is_pyq)->toBeTrue()
        ->and($question->pyq_exam_name)->toBe('JEE Main')
        ->and($question->pyq_exam_year)->toBe(2024);
});

it('requires exam name and year once is_pyq is toggled on', function () {
    Livewire::test(CreateQuestion::class)
        ->fillForm([
            'course_id' => $this->course->id,
            'type' => 'mcq',
            'stem' => 'What is 2 + 2?',
            'options' => ['3', '4', '5'],
            'correct_answer_mcq' => '4',
            'difficulty' => 'easy',
            'is_pyq' => true,
        ])
        ->call('create')
        ->assertHasFormErrors(['pyq_exam_name', 'pyq_exam_year']);
});
