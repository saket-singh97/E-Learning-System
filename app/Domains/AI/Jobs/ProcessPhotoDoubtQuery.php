<?php

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Models\PhotoDoubtQuery;
use App\Integrations\AiProvider;
use App\Integrations\CommsProvider;
use App\Support\Tenancy\RebindsTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * L14 — calls AiProvider::chatWithImage() (B0) and fills in
 * extracted_question/solution. A driver failure (spend cap, circuit
 * breaker, vendor error) transitions the row to `failed` rather than
 * surfacing as an uncaught job exception the student would never see —
 * same "record the failure on the row, not just the queue" posture
 * HandlesIngestionFailure already established for lesson ingestion, though
 * this doesn't call $this->fail() the way that trait does: an AI failure
 * here is an expected, handled outcome (same as ProcessOmrSheetScan's own
 * "no matching enrolment" business-failure paths), not a queue-worthy
 * retry signal.
 *
 * chatWithImage() has no structured-output mode (B0's own contract — see
 * its docblock), so the prompt asks for a fixed "Question: ...\nSolution:
 * ..." plain-text shape and this job parses it. A response that doesn't
 * match (e.g. FakeAiDriver's own filler text, or a real vendor ignoring
 * the format) falls back to the whole response as `solution` with
 * `extracted_question` left null, rather than guessing or crashing.
 */
class ProcessPhotoDoubtQuery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $queryId,
        public readonly ?int $tenant_id,
    ) {}

    public function middleware(): array
    {
        return [new RebindsTenant];
    }

    public function handle(AiProvider $ai, CommsProvider $comms): void
    {
        $query = PhotoDoubtQuery::findOrFail($this->queryId);
        $query->update(['status' => 'processing']);

        try {
            $result = $ai->chatWithImage(
                messages: [[
                    'role' => 'user',
                    'content' => 'Read the handwritten question in this photo, then respond in exactly '.
                        "this format:\nQuestion: <the question you read>\nSolution: <step-by-step solution>",
                ]],
                imageDiskPath: $query->image_disk_path,
            );

            $parsed = $this->parse($result->text ?? '');

            $query->update([
                'status' => 'ready',
                'extracted_question' => $parsed['extracted_question'],
                'solution' => $parsed['solution'],
                'token_cost' => $result->usage,
            ]);
        } catch (Throwable) {
            $query->update(['status' => 'failed']);
        }

        $this->notifyWhatsApp($query->fresh(), $comms);
    }

    /**
     * A WhatsApp-sourced query (S24 B3) has no Livewire UI polling its
     * status, so this job's own completion is the only signal available —
     * the row itself is the source of truth (channel/source_phone), same
     * "the job is the completion signal" posture with no event/listener
     * layer added for what is currently this job's only such consumer. A
     * web-sourced query (channel defaults to 'web') never reaches here.
     */
    private function notifyWhatsApp(PhotoDoubtQuery $query, CommsProvider $comms): void
    {
        if ($query->channel !== 'whatsapp' || blank($query->source_phone)) {
            return;
        }

        $reply = $query->status === 'ready'
            ? "Here's the solution:\n\n{$query->solution}"
            : "Sorry, couldn't solve that photo — try sending a clearer picture.";

        $comms->sendSessionMessage($query->source_phone, $reply);
    }

    /**
     * @return array{extracted_question: ?string, solution: string}
     */
    protected function parse(string $text): array
    {
        if (preg_match('/Question:\s*(.+?)\s*Solution:\s*(.+)/is', $text, $matches) === 1) {
            return ['extracted_question' => trim($matches[1]), 'solution' => trim($matches[2])];
        }

        return ['extracted_question' => null, 'solution' => trim($text)];
    }
}
