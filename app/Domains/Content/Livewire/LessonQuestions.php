<?php

namespace App\Domains\Content\Livewire;

use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\LessonQuestion;
use App\Domains\Content\Models\LessonQuestionReply;
use App\Domains\Content\Models\LessonQuestionVote;
use App\Domains\Content\Services\LessonAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Visible to the same audience LessonAccess already gates the lesson to —
 * no separate visibility rule invented (design doc decision 8). The page
 * this embeds on (content.watch) is already gated by
 * WatchLessonController's own canStream() check, but Livewire's
 * /livewire/update endpoint is a separate, unguarded request (CLAUDE.md) —
 * askQuestion()/reply() re-check canStream() themselves, same reasoning
 * WatchHeartbeatController already re-checks it despite the page load
 * having gated once.
 */
class LessonQuestions extends Component
{
    public Lesson $lesson;

    public string $newQuestionBody = '';

    /** @var array<int, string> */
    public array $replyBodies = [];

    public function mount(Lesson $lesson): void
    {
        $this->lesson = $lesson;
    }

    public function questions(): Collection
    {
        return LessonQuestion::where('lesson_id', $this->lesson->id)
            ->with(['user', 'replies.user', 'votes'])
            ->withCount('votes')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Any viewer with access, one vote per user per question — toggled
     * (same on/off shape WishlistService::toggle() already established,
     * B3), enforced at the DB level by the unique constraint, not an
     * app-level check alone (design doc decision 8).
     */
    public function toggleUpvote(int $questionId): void
    {
        $viewer = auth()->user();

        if (! $viewer || ! app(LessonAccess::class)->canStream($this->lesson, $viewer)) {
            return;
        }

        $question = LessonQuestion::where('lesson_id', $this->lesson->id)->findOrFail($questionId);

        $existing = LessonQuestionVote::where('lesson_question_id', $question->id)
            ->where('user_id', $viewer->id)
            ->first();

        if ($existing) {
            $existing->delete();

            return;
        }

        LessonQuestionVote::create([
            'lesson_question_id' => $question->id,
            'user_id' => $viewer->id,
        ]);
    }

    /**
     * Instructor/admin-only — mirrors how AssignmentSubmission grading is
     * instructor/admin-gated (design doc decision 8). This component has
     * no Filament panel role-middleware to lean on (it's embedded on the
     * student-facing watch page), so the role check lives here.
     */
    public function markResolved(int $questionId): void
    {
        $viewer = auth()->user();

        if (! $viewer || ! $viewer->hasAnyRole(LessonAccess::STAFF_ROLES)) {
            return;
        }

        if (! app(LessonAccess::class)->canStream($this->lesson, $viewer)) {
            return;
        }

        LessonQuestion::where('lesson_id', $this->lesson->id)
            ->findOrFail($questionId)
            ->update(['resolved_at' => now()]);
    }

    public function askQuestion(): void
    {
        $viewer = auth()->user();

        if (! $viewer || ! app(LessonAccess::class)->canStream($this->lesson, $viewer)) {
            return;
        }

        $this->validate(['newQuestionBody' => 'required|string|max:2000']);

        LessonQuestion::create([
            'lesson_id' => $this->lesson->id,
            'user_id' => $viewer->id,
            'body' => $this->newQuestionBody,
        ]);

        $this->newQuestionBody = '';
    }

    public function reply(int $questionId): void
    {
        $viewer = auth()->user();

        if (! $viewer || ! app(LessonAccess::class)->canStream($this->lesson, $viewer)) {
            return;
        }

        $this->validate(["replyBodies.{$questionId}" => 'required|string|max:2000']);

        $question = LessonQuestion::where('lesson_id', $this->lesson->id)->findOrFail($questionId);

        LessonQuestionReply::create([
            'lesson_question_id' => $question->id,
            'user_id' => $viewer->id,
            'body' => $this->replyBodies[$questionId],
        ]);

        $this->replyBodies[$questionId] = '';
    }

    public function render(): View
    {
        $viewer = auth()->user();
        $questions = $this->questions();

        return view('content::lesson-questions', [
            'questions' => $questions,
            'upvotedQuestionIds' => $viewer
                ? LessonQuestionVote::where('user_id', $viewer->id)
                    ->whereIn('lesson_question_id', $questions->pluck('id'))
                    ->pluck('lesson_question_id')
                    ->all()
                : [],
            'canResolve' => (bool) $viewer?->hasAnyRole(LessonAccess::STAFF_ROLES),
        ]);
    }
}
