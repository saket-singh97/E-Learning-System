<?php

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Models\LessonRevisionNote;
use App\Domains\Content\Models\Lesson;
use App\Exceptions\LessonArtifactNotReadyException;
use App\Integrations\AiProvider;
use Illuminate\Support\Collection;

/**
 * L11 — instructor-triggered per-lesson revision notes/summaries, never an
 * auto-generated sweep over the whole course library (S17 design §4
 * decision 4). Versioned by source_artifact_version, never overwritten in
 * place (design §4 decision 9) — a re-ingested lesson's next call is a new
 * row; the old notes stay readable against the old version.
 */
class RevisionNotesGenerator
{
    public function __construct(
        private readonly AiProvider $ai,
    ) {}

    /**
     * Throws rather than returning a placeholder row: source_artifact_version
     * is the whole point of this table's unique constraint (design §3.2), so
     * there is no real version to pin a "not ready yet" row to without either
     * breaking that constraint on a second call or inventing a fake version
     * number. A clean, catchable exception (mapped to 409) is the "refuses
     * gracefully" this guard needs — same posture DoubtChatService::ask()
     * takes toward an unready artifact, just surfaced as an exception here
     * instead of a placeholder chat answer, since there's no chat UI waiting
     * on a response the way DoubtChat's own always-render-something view is.
     */
    public function generateFor(Lesson $lesson): LessonRevisionNote
    {
        $artifact = LessonArtifact::latestReadyFor($lesson->id);

        if ($artifact === null) {
            throw LessonArtifactNotReadyException::forLesson($lesson->id);
        }

        $result = $this->ai->chat(
            messages: [['role' => 'user', 'content' => $this->promptFor($artifact)]],
            responseSchema: ['notes'],
        );

        return LessonRevisionNote::create([
            'lesson_id' => $lesson->id,
            'source_artifact_version' => $artifact->version,
            'status' => 'ready',
            'notes' => $result->structured['notes'] ?? [],
            'token_cost' => $result->usage,
        ]);
    }

    protected function promptFor(LessonArtifact $artifact): string
    {
        $concepts = Collection::make($artifact->concepts ?? [])->pluck('name')->implode(', ');

        return 'Summarize this lecture transcript into concise revision notes (key points a '.
            "student should remember before an exam). Concepts covered: {$concepts}.\n\n".
            "Transcript:\n{$artifact->transcript}";
    }
}
