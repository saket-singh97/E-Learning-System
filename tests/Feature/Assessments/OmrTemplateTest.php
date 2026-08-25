<?php

use App\Domains\Assessments\Models\OmrTemplate;

it('generates the same layout twice for the same inputs — deterministic', function () {
    $first = OmrTemplate::generateLayout(20, 4, 6);
    $second = OmrTemplate::generateLayout(20, 4, 6);

    expect($first)->toBe($second);
});

it('covers every question/option position with no overlapping coordinates', function () {
    $layout = OmrTemplate::generateLayout(15, 4, 6);

    $questionCoords = collect($layout['questions'])
        ->flatMap(fn (array $question): array => $question['options'])
        ->map(fn (array $option): string => "{$option['x']},{$option['y']}");

    expect($questionCoords)->toHaveCount(15 * 4);
    expect($questionCoords->unique())->toHaveCount(15 * 4);
});

it('covers every roll-number-digit position with no overlapping coordinates', function () {
    $layout = OmrTemplate::generateLayout(15, 4, 6);

    $rollCoords = collect($layout['roll_number'])
        ->flatMap(fn (array $column): array => $column['digits'])
        ->map(fn (array $digit): string => "{$digit['x']},{$digit['y']}");

    expect($rollCoords)->toHaveCount(6 * 10);
    expect($rollCoords->unique())->toHaveCount(6 * 10);
});

it('never overlaps a question bubble coordinate with a roll-number bubble coordinate', function () {
    $layout = OmrTemplate::generateLayout(15, 4, 6);

    $questionCoords = collect($layout['questions'])
        ->flatMap(fn (array $question): array => $question['options'])
        ->map(fn (array $option): string => "{$option['x']},{$option['y']}");

    $rollCoords = collect($layout['roll_number'])
        ->flatMap(fn (array $column): array => $column['digits'])
        ->map(fn (array $digit): string => "{$digit['x']},{$digit['y']}");

    expect($questionCoords->intersect($rollCoords))->toBeEmpty();
});

it('scales correctly with a large question count and different option/digit counts', function () {
    $layout = OmrTemplate::generateLayout(100, 5, 10);

    expect($layout['questions'])->toHaveCount(100);
    expect($layout['questions'][0]['options'])->toHaveCount(5);
    expect($layout['roll_number'])->toHaveCount(10);
    expect($layout['roll_number'][0]['digits'])->toHaveCount(10);
});
