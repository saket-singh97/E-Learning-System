<?php

namespace App\Integrations;

final class TranscriptResult
{
    /**
     * @param  array<int, array{start_s: float, end_s: float, text: string}>  $segments
     */
    public function __construct(
        public readonly string $text,
        public readonly array $segments,
    ) {}
}
