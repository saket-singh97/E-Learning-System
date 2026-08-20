<?php

namespace App\Domains\Content\Services;

use App\Domains\AI\Models\LessonArtifact;

/**
 * Design doc decision 8 — cues come straight from the ingestion pipeline's
 * own LessonArtifactChunk rows (start_s/end_s/text), never a new
 * transcription/AI call.
 */
class SubtitleGenerator
{
    public function toSrt(LessonArtifact $artifact): string
    {
        return $this->render($artifact, srt: true);
    }

    public function toVtt(LessonArtifact $artifact): string
    {
        return $this->render($artifact, srt: false);
    }

    private function render(LessonArtifact $artifact, bool $srt): string
    {
        $chunks = $artifact->chunkRows()->orderBy('chunk_idx')->get();

        $blocks = [];

        foreach ($chunks as $index => $chunk) {
            $cue = $this->formatTimestamp((float) $chunk->start_s, $srt).' --> '.$this->formatTimestamp((float) $chunk->end_s, $srt)."\n".trim((string) $chunk->text);

            $blocks[] = $srt ? ($index + 1)."\n".$cue : $cue;
        }

        $body = implode("\n\n", $blocks);

        return $srt ? $body : "WEBVTT\n\n".$body;
    }

    private function formatTimestamp(float $seconds, bool $srt): string
    {
        $totalMs = (int) round(max(0.0, $seconds) * 1000);

        $hours = intdiv($totalMs, 3_600_000);
        $totalMs %= 3_600_000;
        $minutes = intdiv($totalMs, 60_000);
        $totalMs %= 60_000;
        $secs = intdiv($totalMs, 1_000);
        $ms = $totalMs % 1_000;

        return sprintf('%02d:%02d:%02d%s%03d', $hours, $minutes, $secs, $srt ? ',' : '.', $ms);
    }
}
