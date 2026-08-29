<?php

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Models\LessonPdfNote;
use App\Domains\Content\Models\Lesson;
use App\Integrations\AiProvider;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * U1 — auto lecture notes: transcript -> structured PDF, posted within
 * minutes of ingestion finishing (design doc §2.1). One row per lesson,
 * "latest wins" (design doc §3.2) — unlike RevisionNotesGenerator (S17),
 * this never throws when the artifact isn't ready yet: it mirrors
 * DoubtChatService::ask()'s own "still processing" placeholder posture
 * (design doc B0 checklist) since this is fired from a queued listener
 * with no interactive caller to catch an exception.
 */
class LessonPdfNoteGenerator
{
    public function __construct(
        private readonly AiProvider $ai,
    ) {}

    public function generate(Lesson $lesson): LessonPdfNote
    {
        $artifact = LessonArtifact::latestReadyFor($lesson->id);

        if ($artifact === null) {
            return LessonPdfNote::updateOrCreate(
                ['lesson_id' => $lesson->id],
                ['status' => 'pending', 'disk_path' => null, 'generated_at' => null],
            );
        }

        $result = $this->ai->chat(
            messages: [['role' => 'user', 'content' => $this->promptFor($artifact)]],
            responseSchema: ['notes'],
        );

        $notes = $result->structured['notes'] ?? [];
        $diskPath = "lesson-notes/{$lesson->tenant_id}/{$lesson->id}.pdf";

        Pdf::loadView('ai.lesson-pdf-notes', [
            'lesson' => $lesson,
            'notes' => $notes,
        ])->save($diskPath, 'local');

        return LessonPdfNote::updateOrCreate(
            ['lesson_id' => $lesson->id],
            ['status' => 'ready', 'disk_path' => $diskPath, 'generated_at' => now()],
        );
    }

    protected function promptFor(LessonArtifact $artifact): string
    {
        return 'Structure this lecture transcript into concise lecture notes '.
            "(key points, in the order they were taught).\n\nTranscript:\n{$artifact->transcript}";
    }
}
