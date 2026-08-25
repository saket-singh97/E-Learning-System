<?php

namespace App\Integrations\Drivers;

use App\Integrations\AiProvider;
use App\Integrations\ChatResult;
use App\Integrations\TranscriptResult;

/**
 * Deterministic, hash-seeded fake — same input always produces the same
 * output, so pipeline tests and pgvector similarity search stay reproducible
 * without a real vendor API key. Real driver (OpenAI/Whisper/etc.) is a
 * fast-follow; every call site here is written against the interface only.
 */
class FakeAiDriver implements AiProvider
{
    /**
     * Must match lesson_artifact_chunks.embedding's vector(D) width — the
     * embedding model decision recorded in the S3 plan/design docs (Block 0).
     */
    public const DIMENSIONS = 1536;

    private const WORD_BANK = [
        'energy', 'motion', 'vector', 'force', 'tenant', 'lesson', 'gradient',
        'integral', 'circuit', 'photon', 'momentum', 'velocity', 'friction',
        'current', 'voltage', 'syllabus', 'concept', 'chapter',
    ];

    public function transcribe(string $diskPath): TranscriptResult
    {
        $seed = self::seedFor($diskPath);
        $sentenceCount = 3 + ($seed % 3);

        $segments = [];
        $cursor = 0.0;

        for ($i = 0; $i < $sentenceCount; $i++) {
            $duration = 2.0 + (($seed + $i) % 5);

            $segments[] = [
                'start_s' => $cursor,
                'end_s' => $cursor + $duration,
                'text' => self::fakeSentence($seed + $i),
            ];

            $cursor += $duration;
        }

        return new TranscriptResult(
            text: implode(' ', array_column($segments, 'text')),
            segments: $segments,
        );
    }

    public function chat(array $messages, ?array $responseSchema = null): ChatResult
    {
        $seed = self::seedFor(json_encode($messages));
        $usage = self::fakeUsageFor($seed);

        if ($responseSchema === null) {
            return new ChatResult(
                text: self::fakeSentence($seed).' '.self::fakeSentence($seed + 1),
                usage: $usage,
            );
        }

        $structured = [];

        foreach ($responseSchema as $key => $value) {
            $field = is_int($key) ? $value : $key;
            $structured[$field] = self::fakeValueFor($field, $seed);
        }

        return new ChatResult(structured: $structured, usage: $usage);
    }

    public function embed(string $text): array
    {
        $vector = [];

        for ($i = 0; $i < self::DIMENSIONS; $i++) {
            $vector[] = (float) ((self::seedFor($text.'#'.$i) % 2000) / 1000 - 1);
        }

        return $vector;
    }

    public function chatWithImage(array $messages, string $imageDiskPath): ChatResult
    {
        $seed = self::seedFor(json_encode($messages).'#'.$imageDiskPath);
        $usage = self::fakeUsageFor($seed);

        return new ChatResult(
            text: self::fakeSentence($seed).' '.self::fakeSentence($seed + 1),
            usage: $usage,
        );
    }

    private static function seedFor(string $input): int
    {
        return crc32($input);
    }

    private static function fakeSentence(int $seed): string
    {
        $words = [];

        for ($i = 0; $i < 6; $i++) {
            $words[] = self::WORD_BANK[($seed + $i) % count(self::WORD_BANK)];
        }

        return ucfirst(implode(' ', $words)).'.';
    }

    private static function fakeUsageFor(int $seed): array
    {
        $in = 50 + ($seed % 500);
        $out = 50 + (($seed + 7) % 500);

        return ['in' => $in, 'out' => $out, 'usd' => round(($in + $out) * 0.000002, 6)];
    }

    /**
     * concepts/syllabus_map/difficulty/seed_questions get contract-shaped
     * fake data (S1 §5) since ExtractLessonConcepts (S3 Block 3) is a real
     * caller, not a placeholder — everything else falls back to a generic
     * scalar, which is all a caller with an unknown schema can rely on.
     */
    private static function fakeValueFor(string $field, int $seed): mixed
    {
        $fieldSeed = $seed + crc32($field);
        $levels = ['easy', 'medium', 'hard'];

        return match ($field) {
            'concepts' => [[
                'name' => self::fakeSentence($fieldSeed),
                'confidence' => (60 + $fieldSeed % 40) / 100,
                'chunk_idxs' => [0],
            ]],
            'syllabus_map' => [
                'board' => 'CBSE',
                'subject' => 'Physics',
                'chapter' => self::fakeSentence($fieldSeed),
                'topic' => self::fakeSentence($fieldSeed + 1),
            ],
            'difficulty' => [
                'level' => $levels[$fieldSeed % 3],
                'rationale' => self::fakeSentence($fieldSeed),
            ],
            'seed_questions' => [[
                'type' => 'mcq',
                'stem' => self::fakeSentence($fieldSeed),
                'options' => [self::fakeSentence($fieldSeed + 1), self::fakeSentence($fieldSeed + 2)],
                'answer' => self::fakeSentence($fieldSeed + 1),
                'explanation' => self::fakeSentence($fieldSeed + 3),
                'concept' => self::fakeSentence($fieldSeed),
                'difficulty' => $levels[$fieldSeed % 3],
            ]],
            default => str_ends_with($field, '_s')
                || str_contains($field, 'cost')
                || str_contains($field, 'count')
                ? $fieldSeed % 100
                : self::fakeSentence($fieldSeed),
        };
    }
}
