<?php

namespace App\Integrations\Drivers;

use App\Domains\AI\Services\AiSpendCap;
use App\Exceptions\AiSpendCapExceededException;
use App\Integrations\AiProvider;
use App\Integrations\ChatResult;
use App\Integrations\TranscriptResult;
use App\Support\Tenancy\CurrentTenant;

/**
 * Decorates the bound AiProvider (S9 B12), not a sixth guard duplicated
 * across every call site. Six call sites dispatch a real AI call in this
 * app (TranscribeLesson/ExtractLessonConcepts/EmbedLessonChunks,
 * DoubtChatService x2, QuestionGenerator) and five of them resolve
 * AiProvider fresh from the container rather than holding a long-lived
 * instance — wrapping the container binding itself (AppServiceProvider)
 * covers all six with one change instead of six separate inline checks.
 *
 * Pre-checks the running total before allowing a call through, rather than
 * capturing each call's own actual cost afterward — unlike bandwidth
 * (where the exact byte count is known before serving), a call's real
 * spend isn't known until the provider responds and the caller writes its
 * own token_cost column, unchanged by this block. AiProvider::embed()
 * doesn't even return a cost figure at all (confirmed by reading its
 * contract) — a pre-check against TokenCostLedger's own running total is
 * the only enforcement point available without changing that contract.
 */
class SpendCapGuardedAiDriver implements AiProvider
{
    public function __construct(
        private readonly AiProvider $inner,
        private readonly AiSpendCap $spendCap,
    ) {}

    public function transcribe(string $diskPath): TranscriptResult
    {
        $this->assertWithinCap();

        return $this->inner->transcribe($diskPath);
    }

    public function chat(array $messages, ?array $responseSchema = null): ChatResult
    {
        $this->assertWithinCap();

        return $this->inner->chat($messages, $responseSchema);
    }

    public function embed(string $text): array
    {
        $this->assertWithinCap();

        return $this->inner->embed($text);
    }

    public function chatWithImage(array $messages, string $imageDiskPath): ChatResult
    {
        $this->assertWithinCap();

        return $this->inner->chatWithImage($messages, $imageDiskPath);
    }

    protected function assertWithinCap(): void
    {
        $tenant = app(CurrentTenant::class)->get();

        if ($this->spendCap->remainingFor($tenant) <= 0.0) {
            throw AiSpendCapExceededException::forTenant($tenant->id);
        }
    }
}
