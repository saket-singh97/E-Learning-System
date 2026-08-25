<?php

use App\Integrations\AiProvider;
use App\Integrations\ChatResult;
use App\Integrations\Drivers\FakeAiDriver;
use App\Integrations\Drivers\SpendCapGuardedAiDriver;
use App\Integrations\TranscriptResult;

it('resolves AiProvider from the container as FakeAiDriver wrapped in the spend-cap guard', function () {
    // S9 B12 wrapped the binding in SpendCapGuardedAiDriver rather than
    // binding FakeAiDriver directly — every call site resolves AiProvider
    // fresh from the container, so decorating the binding itself is what
    // makes the spend cap apply everywhere with one change (see
    // SpendCapGuardedAiDriverTest for the decorator's own behavior).
    expect(app(AiProvider::class))->toBeInstanceOf(SpendCapGuardedAiDriver::class);
});

it('transcribes the same file to the same transcript every time', function () {
    $driver = new FakeAiDriver;

    $first = $driver->transcribe('videos/1/lecture.mp4');
    $second = $driver->transcribe('videos/1/lecture.mp4');

    expect($first)->toBeInstanceOf(TranscriptResult::class)
        ->and($first->text)->toBe($second->text)
        ->and($first->segments)->toBe($second->segments)
        ->and($first->segments)->not->toBeEmpty();
});

it('transcribes different files to different transcripts', function () {
    $driver = new FakeAiDriver;

    $a = $driver->transcribe('videos/1/lecture-a.mp4');
    $b = $driver->transcribe('videos/1/lecture-b.mp4');

    expect($a->text)->not->toBe($b->text);
});

it('returns plain text from chat when no responseSchema is given', function () {
    $driver = new FakeAiDriver;

    $result = $driver->chat([['role' => 'user', 'content' => 'Explain velocity.']]);

    expect($result)->toBeInstanceOf(ChatResult::class)
        ->and($result->text)->toBeString()->not->toBeEmpty()
        ->and($result->structured)->toBeNull()
        ->and($result->usage)->toHaveKeys(['in', 'out', 'usd']);
});

it('returns structured output shaped by responseSchema, same input same output', function () {
    $driver = new FakeAiDriver;
    $messages = [['role' => 'user', 'content' => 'Extract concepts.']];
    $schema = ['concepts', 'difficulty', 'token_cost'];

    $first = $driver->chat($messages, $schema);
    $second = $driver->chat($messages, $schema);

    expect($first->text)->toBeNull()
        ->and($first->structured)->toHaveKeys(['concepts', 'difficulty', 'token_cost'])
        ->and($first->structured)->toBe($second->structured)
        ->and($first->structured['token_cost'])->toBeInt()
        ->and($first->structured['concepts'][0])->toHaveKeys(['name', 'confidence', 'chunk_idxs'])
        ->and($first->structured['difficulty'])->toHaveKeys(['level', 'rationale'])
        ->and($first->usage)->toHaveKeys(['in', 'out', 'usd']);
});

it('embeds text into a D-length vector stable across two calls', function () {
    $driver = new FakeAiDriver;

    $first = $driver->embed('What is velocity?');
    $second = $driver->embed('What is velocity?');

    expect($first)->toHaveCount(FakeAiDriver::DIMENSIONS)
        ->and($first)->toBe($second)
        ->and($first[0])->toBeFloat();
});

it('embeds different text into different vectors', function () {
    $driver = new FakeAiDriver;

    $a = $driver->embed('What is velocity?');
    $b = $driver->embed('What is momentum?');

    expect($a)->not->toBe($b);
});

it('returns a deterministic canned response from chatWithImage, same input same output', function () {
    $driver = new FakeAiDriver;
    $messages = [['role' => 'user', 'content' => 'Solve the question in this photo.']];

    $first = $driver->chatWithImage($messages, 'photo-doubts/1/question.jpg');
    $second = $driver->chatWithImage($messages, 'photo-doubts/1/question.jpg');

    expect($first)->toBeInstanceOf(ChatResult::class)
        ->and($first->text)->toBeString()->not->toBeEmpty()
        ->and($first->structured)->toBeNull()
        ->and($first->usage)->toHaveKeys(['in', 'out', 'usd'])
        ->and($first->text)->toBe($second->text);
});

it('returns a different chatWithImage response for a different image path', function () {
    $driver = new FakeAiDriver;
    $messages = [['role' => 'user', 'content' => 'Solve the question in this photo.']];

    $a = $driver->chatWithImage($messages, 'photo-doubts/1/question-a.jpg');
    $b = $driver->chatWithImage($messages, 'photo-doubts/999/question-far-apart.jpg');

    expect($a->text)->not->toBe($b->text);
});
