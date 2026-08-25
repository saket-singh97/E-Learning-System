<?php

namespace App\Domains\Assessments\Filament\Resources\Questions\Pages\Concerns;

/**
 * questions.correct_answer holds a different shape per type (a matched
 * option string for mcq/true_false, free text for short_answer, a
 * {value, tolerance} pair for numerical) — one column, four widgets. The
 * form exposes one virtual field per type instead (dehydrated, but not a
 * real column), and this trait composes/decomposes between them and the
 * real `correct_answer`/`options` columns. Shared by CreateQuestion and
 * EditQuestion rather than duplicated, since both need it identically.
 *
 * S16 B8 (I6 pt.2) — extends the same pattern to the 4 new types.
 * `multi_select`/`fill_in_blank` reuse `mcq`'s own "ids are just the
 * visible text" convention (no leak risk — the option list is meant to
 * be fully visible either way). `matching`/`ordering` are different: the
 * correct pairing/order must not be trivially readable off the stored
 * `options` shape, so both are built through a one-time shuffle at
 * authoring time — `buildMatchingShape()`/`buildOrderingShape()` shuffle
 * the right-hand/item list once and record `correct_answer` as indices
 * into the *shuffled* result, so a naive side-by-side or top-to-bottom
 * render of `options` doesn't hand the answer to the student for free.
 */
trait NormalizesQuestionFormData
{
    /**
     * Virtual per-type fields -> real correct_answer/options columns,
     * before create/save.
     */
    protected function normalizeQuestionFormData(array $data): array
    {
        $type = $data['type'] ?? null;

        if ($type === 'matching') {
            $shape = $this->buildMatchingShape($data['matching_pairs'] ?? []);
            $data['options'] = $shape['options'];
            $data['correct_answer'] = $shape['correct_answer'];
        } elseif ($type === 'ordering') {
            $shape = $this->buildOrderingShape($data['ordering_items'] ?? []);
            $data['options'] = $shape['options'];
            $data['correct_answer'] = $shape['correct_answer'];
        } else {
            $data['options'] = match ($type) {
                'mcq', 'multi_select' => $data['options'] ?? [],
                'true_false' => ['True', 'False'],
                default => null,
            };

            $data['correct_answer'] = match ($type) {
                // Cast explicitly: a purely-numeric option string ("4", common
                // for the numerical-MCQ style this app's own JEE/NEET audience
                // uses) becomes an array key when Select's options() builds
                // [option => option] from the tag list — PHP silently
                // normalises a numeric-string array key to a real int, and
                // Filament resolves the submitted state through that key. Left
                // uncast, correct_answer (int 4) would no longer match any
                // string in options (["3","4","5"]) for later grading.
                'mcq' => isset($data['correct_answer_mcq']) ? (string) $data['correct_answer_mcq'] : null,
                'true_false' => $data['correct_answer_true_false'] ?? null,
                'short_answer' => $data['correct_answer_short'] ?? '',
                'numerical' => [
                    'value' => (float) ($data['correct_answer_numerical_value'] ?? 0),
                    'tolerance' => (float) ($data['correct_answer_numerical_tolerance'] ?? 0),
                ],
                // Cast every selected key to string for the same reason the
                // mcq branch above does: CheckboxList's own options() array
                // (built the same [text => text] way) silently turns a
                // purely-numeric option string into an int array key, so an
                // uncast state here would submit ints that no longer match
                // the string values grade() compares against.
                'multi_select' => ['correct_option_ids' => array_map('strval', $data['correct_answer_multi_select'] ?? [])],
                'fill_in_blank' => ['accepted_answers' => array_values($data['correct_answer_fill_in_blank'] ?? [])],
                default => null,
            };
        }

        unset(
            $data['correct_answer_mcq'],
            $data['correct_answer_true_false'],
            $data['correct_answer_short'],
            $data['correct_answer_numerical_value'],
            $data['correct_answer_numerical_tolerance'],
            $data['correct_answer_multi_select'],
            $data['correct_answer_fill_in_blank'],
            $data['matching_pairs'],
            $data['ordering_items'],
        );

        return $data;
    }

    /**
     * The reverse, before the edit form fills from an existing record —
     * without this, re-opening a question for edit would show its
     * correct-answer widget empty instead of the actual saved answer.
     */
    protected function denormalizeQuestionFormData(array $data): array
    {
        $type = $data['type'] ?? null;
        $correctAnswer = $data['correct_answer'] ?? null;
        $options = $data['options'] ?? null;

        $data['correct_answer_mcq'] = $type === 'mcq' ? $correctAnswer : null;
        $data['correct_answer_true_false'] = $type === 'true_false' ? $correctAnswer : null;
        $data['correct_answer_short'] = $type === 'short_answer' ? $correctAnswer : null;
        $data['correct_answer_numerical_value'] = $type === 'numerical' ? ($correctAnswer['value'] ?? null) : null;
        $data['correct_answer_numerical_tolerance'] = $type === 'numerical' ? ($correctAnswer['tolerance'] ?? null) : null;
        $data['correct_answer_multi_select'] = $type === 'multi_select' ? ($correctAnswer['correct_option_ids'] ?? []) : [];
        $data['correct_answer_fill_in_blank'] = $type === 'fill_in_blank' ? ($correctAnswer['accepted_answers'] ?? []) : [];
        $data['matching_pairs'] = $type === 'matching' ? $this->denormalizeMatchingRows($options ?? [], $correctAnswer ?? []) : [];
        $data['ordering_items'] = $type === 'ordering' ? $this->denormalizeOrderingRows($options ?? [], $correctAnswer ?? []) : [];

        return $data;
    }

    /**
     * @param  array<int, array{left?: string, right?: string}>  $pairRows
     * @return array{options: array{left: array<int, string>, right: array<int, string>}, correct_answer: array{pairs: array<int, array{0: int, 1: int}>}}
     */
    protected function buildMatchingShape(array $pairRows): array
    {
        $lefts = collect($pairRows)->pluck('left')->map(fn (mixed $v): string => (string) $v)->values();
        $rights = collect($pairRows)->pluck('right')->map(fn (mixed $v): string => (string) $v)->values();

        // B17 review pass — shuffles a permutation of *indices*, not the
        // text values themselves: searching the shuffled result by value
        // (the original approach) silently resolved two identical
        // right-hand labels (e.g. "None of the above" used twice) to the
        // same shuffled slot, corrupting correct_answer for one of the
        // pairs. An index permutation is unique by construction, so this
        // can't collide regardless of duplicate label text.
        $permutation = $rights->isEmpty() ? [] : range(0, $rights->count() - 1);
        shuffle($permutation);

        $shuffledRights = collect($permutation)->map(fn (int $originalIndex): string => $rights->get($originalIndex));

        $pairs = $lefts->map(function (string $left, int $leftIndex) use ($permutation): array {
            $shuffledIndex = array_search($leftIndex, $permutation, true);

            return [$leftIndex, $shuffledIndex === false ? 0 : $shuffledIndex];
        })->values()->all();

        return [
            'options' => ['left' => $lefts->all(), 'right' => $shuffledRights->all()],
            'correct_answer' => ['pairs' => $pairs],
        ];
    }

    /**
     * @param  array{left: array<int, string>, right: array<int, string>}  $options
     * @param  array{pairs?: array<int, array{0: int, 1: int}>}  $correctAnswer
     * @return array<int, array{left: string, right: string}>
     */
    protected function denormalizeMatchingRows(array $options, array $correctAnswer): array
    {
        $lefts = $options['left'] ?? [];
        $rights = $options['right'] ?? [];

        return collect($correctAnswer['pairs'] ?? [])
            ->map(fn (array $pair): array => [
                'left' => $lefts[$pair[0]] ?? '',
                'right' => $rights[$pair[1]] ?? '',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{label?: string}>  $itemRows  entered in the correct order
     * @return array{options: array<int, string>, correct_answer: array{correct_sequence: array<int, int>}}
     */
    protected function buildOrderingShape(array $itemRows): array
    {
        $itemsInCorrectOrder = collect($itemRows)->pluck('label')->map(fn (mixed $v): string => (string) $v)->values();

        // B17 review pass — same index-permutation fix as
        // buildMatchingShape() above: a value search after shuffling
        // silently mis-resolved two identical item labels to the same
        // shuffled slot.
        $permutation = $itemsInCorrectOrder->isEmpty() ? [] : range(0, $itemsInCorrectOrder->count() - 1);
        shuffle($permutation);

        $shuffledOptions = collect($permutation)->map(fn (int $originalIndex): string => $itemsInCorrectOrder->get($originalIndex));

        $correctSequence = collect($permutation === [] ? [] : range(0, $itemsInCorrectOrder->count() - 1))
            ->map(function (int $correctOrderIndex) use ($permutation): int {
                $shuffledIndex = array_search($correctOrderIndex, $permutation, true);

                return $shuffledIndex === false ? 0 : $shuffledIndex;
            })
            ->values()
            ->all();

        return [
            'options' => $shuffledOptions->all(),
            'correct_answer' => ['correct_sequence' => $correctSequence],
        ];
    }

    /**
     * @param  array<int, string>  $options
     * @param  array{correct_sequence?: array<int, int>}  $correctAnswer
     * @return array<int, array{label: string}>
     */
    protected function denormalizeOrderingRows(array $options, array $correctAnswer): array
    {
        return collect($correctAnswer['correct_sequence'] ?? [])
            ->map(fn (int $index): array => ['label' => $options[$index] ?? ''])
            ->values()
            ->all();
    }
}
