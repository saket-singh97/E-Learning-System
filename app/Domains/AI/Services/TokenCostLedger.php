<?php

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\DoubtChatMessage;
use App\Domains\AI\Models\LessonArtifact;
use App\Domains\Assessments\Models\Question;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Measurement only, no cap or billing — same reasoning S2 gave for
 * BandwidthUsageDaily: enforcing a spend cap against plan tiers that don't
 * exist yet is a P2 problem. Unlike bandwidth, this reads straight off
 * lesson_artifacts.token_cost / doubt_chat_messages.token_cost /
 * questions.token_cost rather than writing its own rollup table — the
 * per-row figures already exist, written once by ExtractLessonConcepts,
 * DoubtChatService::ask(), and QuestionGenerator::generate() respectively.
 *
 * All three sources are summed: ingestion is a one-time cost per lesson,
 * doubt-chat questions are the ongoing, most user-driven AI cost in the
 * app, and question generation is a third, independent real chat() call
 * site — a "spend today" figure missing any one of them silently
 * undercounts. questions.token_cost was added in S9 B14, once the review
 * pass found QuestionGenerator::generate() discarding its own real
 * ChatResult::$usage on every call, so B12's spend cap never actually saw
 * this source's cost despite gating it. `count` stays ingestion-only (the
 * widget's "N lesson ingestion(s)" label describes it), not a mixed count
 * of three different kinds of row.
 *
 * No explicit tenant filter here on purpose: BelongsToTenant's global scope
 * already narrows to CurrentTenant when one is bound (/admin), and is a
 * no-op when none is (/super's bare host) — which is exactly "platform-wide
 * across tenants" for free, not a bypass.
 */
class TokenCostLedger
{
    /**
     * @return array{in: int, out: int, usd: float, count: int}
     */
    public function totalForDate(DateTimeInterface $date): array
    {
        $ingestion = $this->sum(
            LessonArtifact::whereDate('created_at', $date)
                ->whereNotNull('token_cost')
                ->get()
        );

        $doubtChat = $this->sum(
            DoubtChatMessage::whereDate('created_at', $date)
                ->whereNotNull('token_cost')
                ->get()
        );

        $questionGeneration = $this->sum(
            Question::whereDate('created_at', $date)
                ->whereNotNull('token_cost')
                ->get()
        );

        return [
            'in' => $ingestion['in'] + $doubtChat['in'] + $questionGeneration['in'],
            'out' => $ingestion['out'] + $doubtChat['out'] + $questionGeneration['out'],
            'usd' => round($ingestion['usd'] + $doubtChat['usd'] + $questionGeneration['usd'], 6),
            'count' => $ingestion['count'],
        ];
    }

    /**
     * @param  Collection<int, LessonArtifact|DoubtChatMessage|Question>  $rows
     * @return array{in: int, out: int, usd: float, count: int}
     */
    private function sum(Collection $rows): array
    {
        return [
            'in' => (int) $rows->sum(fn ($row) => $row->token_cost['in'] ?? 0),
            'out' => (int) $rows->sum(fn ($row) => $row->token_cost['out'] ?? 0),
            'usd' => round($rows->sum(fn ($row) => $row->token_cost['usd'] ?? 0), 6),
            'count' => $rows->count(),
        ];
    }
}
