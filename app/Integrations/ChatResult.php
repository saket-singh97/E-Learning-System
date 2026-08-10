<?php

namespace App\Integrations;

final class ChatResult
{
    /**
     * @param  array{in: int, out: int, usd: float}|null  $usage
     */
    public function __construct(
        public readonly ?string $text = null,
        public readonly ?array $structured = null,
        public readonly ?array $usage = null,
    ) {}
}
