<?php

namespace App\Integrations;

interface AiProvider
{
    /**
     * Transcribe the video/audio file at the given disk path.
     */
    public function transcribe(string $diskPath): TranscriptResult;

    /**
     * Send a chat/completion request. Passing $responseSchema switches the
     * driver into structured-output mode, returning ChatResult::$structured
     * instead of ChatResult::$text (used for concept/question extraction).
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<int|string, mixed>|null  $responseSchema
     */
    public function chat(array $messages, ?array $responseSchema = null): ChatResult;

    /**
     * Embed a string into a fixed-length vector for pgvector similarity search.
     *
     * @return array<int, float>
     */
    public function embed(string $text): array;
}
