<?php

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\CourseOutlineDraft;
use App\Domains\AI\Models\LessonArtifact;
use App\Domains\Content\Models\Lesson;
use App\Integrations\AiProvider;
use App\Models\User;

/**
 * U8 — sibling to CourseOutlineGenerator (design doc §4 decision 6): same
 * AiProvider::chat() + responseSchema=['outline'] contract, reusing
 * CourseOutlineDraft itself rather than a new table (design doc §3.2 — U8
 * has no new schema). The only difference from CourseOutlineGenerator::generate()
 * is the prompt: built from the *preceding* lesson's own
 * LessonArtifact.syllabus_map instead of a blank topic+level the caller
 * supplies.
 */
class LessonPlanAssistant
{
    public function __construct(
        private readonly AiProvider $ai,
    ) {}

    /**
     * Null, not an exception — same "nothing to draft from yet" placeholder
     * posture DoubtChatService::ask() already takes toward an unready
     * artifact, since a Filament action (this method's only caller) has a
     * notification to show either way, not a chat bubble waiting on a
     * response.
     */
    public function generateFor(Lesson $precedingLesson, User $creator): ?CourseOutlineDraft
    {
        $syllabusMap = LessonArtifact::latestReadyFor($precedingLesson->id)?->syllabus_map;

        if (empty($syllabusMap)) {
            return null;
        }

        $result = $this->ai->chat(
            messages: [['role' => 'user', 'content' => $this->promptFor($precedingLesson, $syllabusMap)]],
            responseSchema: ['outline'],
        );

        return CourseOutlineDraft::create([
            'created_by_user_id' => $creator->id,
            'topic' => "Next topic after \"{$precedingLesson->title}\"",
            'status' => 'draft',
            'generated_outline' => $result->structured['outline'] ?? [],
            'token_cost' => $result->usage,
        ]);
    }

    /**
     * @param  array{board?: string, subject?: string, chapter?: string, topic?: string}  $syllabusMap
     */
    protected function promptFor(Lesson $precedingLesson, array $syllabusMap): string
    {
        $board = $syllabusMap['board'] ?? '';
        $subject = $syllabusMap['subject'] ?? '';
        $chapter = $syllabusMap['chapter'] ?? '';
        $topic = $syllabusMap['topic'] ?? '';

        return "The preceding lesson \"{$precedingLesson->title}\" covered {$board} {$subject}, chapter ".
            "\"{$chapter}\", topic \"{$topic}\". Draft a lesson plan for the next topic in this syllabus ".
            'sequence. Return an array of sections, each with a section_title and a lessons array of '.
            '{title, description}.';
    }
}
