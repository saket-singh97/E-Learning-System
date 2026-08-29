<?php

namespace App\Domains\Assessments\Services;

use App\Domains\AI\Models\LessonArtifact;
use App\Domains\Assessments\Models\Question;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\Lesson;
use App\Integrations\AiProvider;
use Illuminate\Support\Collection;

/**
 * L5's "generate a question paper from the teacher's own notes" — two
 * paths, both writing status='draft'/source='ai_generated' Questions.
 * L6's review gate is enforced where a Question is *drawn* into an
 * attempt (a draft one never is, per design doc §3.2), not here — this
 * class's only job is getting the AI's draft into the review queue, not
 * guaranteeing it's already correct.
 */
class QuestionGenerator
{
    /**
     * Promote a lesson's already-extracted seed_questions (S3's
     * ExtractLessonConcepts writes these; nothing read them before S6) into
     * draft Question rows. No AI call — this is free, the extraction
     * already happened during ingestion.
     *
     * Idempotent per lesson (S22 B13 review pass): S6 had exactly one
     * caller, each lesson promoted at most once by a human action, so this
     * never needed a repeat-call guard. S22 added two callers that legitimately
     * call this more than once for the same lesson — DailyPracticeSetGenerator
     * (a lesson stays in a batch's own cumulative "taught so far" window
     * across many days) and GenerateQuizFromLiveClass (a retried ingestion
     * re-fires LessonArtifactReady) — both would otherwise mint a fresh
     * duplicate batch of draft Questions on every repeat call. Existing
     * ai_generated rows for the lesson are the guard.
     *
     * @return Collection<int, Question>
     */
    public function fromSeedQuestions(Lesson $lesson): Collection
    {
        $alreadyPromoted = Question::where('lesson_id', $lesson->id)
            ->where('source', 'ai_generated')
            ->get();

        if ($alreadyPromoted->isNotEmpty()) {
            return $alreadyPromoted;
        }

        $seedQuestions = LessonArtifact::latestReadyFor($lesson->id)?->seed_questions ?? [];

        if (empty($seedQuestions)) {
            return collect();
        }

        $course = $lesson->section?->course;

        if ($course === null) {
            return collect();
        }

        return collect($seedQuestions)->map(
            fn (array $seed): Question => $this->createDraftFromSeed($course, $lesson, $seed)
        )->values();
    }

    /**
     * Blueprint-driven fresh generation, for when seed_questions don't
     * cover what the teacher's asking for (a topic with no ingested
     * lecture yet, or simply more questions than one lesson seeded).
     *
     * One chat() call per requested question rather than one call for the
     * whole blueprint — reuses ExtractLessonConcepts' exact
     * responseSchema=['seed_questions'] contract instead of inventing a
     * new "array of N questions" shape AiProvider (and FakeAiDriver) don't
     * speak yet (design doc §2.3: "no new AI plumbing").
     *
     * @param  array<int, array{concept?: string, difficulty?: string, type?: string, count?: int}>  $blueprint
     * @return Collection<int, Question>
     */
    public function generate(Course $course, array $blueprint): Collection
    {
        $questions = collect();

        foreach ($blueprint as $line) {
            $count = max(1, (int) ($line['count'] ?? 1));

            for ($i = 0; $i < $count; $i++) {
                $result = app(AiProvider::class)->chat(
                    messages: [['role' => 'user', 'content' => $this->promptFor($course, $line, $i)]],
                    responseSchema: ['seed_questions'],
                );

                $seed = $result->structured['seed_questions'][0] ?? null;

                if ($seed === null) {
                    continue;
                }

                $questions->push($this->createDraftFromSeed($course, null, $seed, $result->usage));
            }
        }

        return $questions->values();
    }

    /**
     * @param  array{concept?: string, difficulty?: string, type?: string, count?: int}  $line
     */
    protected function promptFor(Course $course, array $line, int $variant): string
    {
        $type = $line['type'] ?? 'mcq';
        $difficulty = $line['difficulty'] ?? 'medium';
        $concept = $line['concept'] ?? $course->title;

        return "Write one {$difficulty} {$type} exam question about \"{$concept}\" ".
            "for the course \"{$course->title}\" (variant {$variant}).";
    }

    /**
     * $usage is null for a question promoted via fromSeedQuestions() (no AI
     * call made — the extraction already happened during ingestion) and
     * the real chat() usage for one generated via generate(). Found
     * missing entirely by the S9 B14 review pass: generate() discarded
     * ChatResult::$usage on every real call, so TokenCostLedger — and by
     * extension B12's own spend cap — never saw a single one of these
     * questions' real cost, same jsonb {in, out, usd} shape
     * lesson_artifacts/doubt_chat_messages already use.
     *
     * @param  array{type?: string, stem?: string, options?: array<int, string>, answer?: mixed, explanation?: string, concept?: string, difficulty?: string}  $seed
     * @param  array{in: int, out: int, usd: float}|null  $usage
     */
    protected function createDraftFromSeed(Course $course, ?Lesson $lesson, array $seed, ?array $usage = null): Question
    {
        $type = $seed['type'] ?? 'mcq';

        return Question::create([
            'course_id' => $course->id,
            'lesson_id' => $lesson?->id,
            'type' => $type,
            'stem' => $seed['stem'] ?? '',
            'options' => in_array($type, ['mcq', 'true_false'], true) ? ($seed['options'] ?? []) : null,
            'correct_answer' => $this->correctAnswerFromSeed($type, $seed['answer'] ?? null),
            'explanation' => $seed['explanation'] ?? null,
            'difficulty' => $seed['difficulty'] ?? 'medium',
            'concept' => $seed['concept'] ?? null,
            'status' => 'draft',
            'source' => 'ai_generated',
            'token_cost' => $usage,
        ]);
    }

    /**
     * FakeAiDriver's seed_questions shape always returns a plain string
     * `answer` (never a {value, tolerance} pair — it doesn't vary output by
     * requested type). Handled defensively for a real vendor that does
     * return a structured numerical answer, not just for the fake.
     */
    protected function correctAnswerFromSeed(string $type, mixed $answer): mixed
    {
        if ($type === 'numerical') {
            if (is_array($answer)) {
                return [
                    'value' => (float) ($answer['value'] ?? 0),
                    'tolerance' => (float) ($answer['tolerance'] ?? 0),
                ];
            }

            return ['value' => (float) $answer, 'tolerance' => 0.0];
        }

        return (string) $answer;
    }
}
