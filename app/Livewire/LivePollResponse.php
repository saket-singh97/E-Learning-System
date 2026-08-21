<?php

namespace App\Livewire;

use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\LivePoll;
use App\Domains\Batches\Models\LivePollResponse as LivePollResponseModel;
use App\Domains\Content\Services\LessonAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Component;

/**
 * Student-side poll response (design doc G9 pt.3, decision 12) — sits on
 * the syllabus tree's own live_session row, next to the Join link (same
 * embed point, since students leave the app once they click Join —
 * design doc §3.3). Authorization reuses
 * LessonAccess::canJoinLive()'s own verdict rather than a new check, the
 * exact "one place answers this" gate the join button itself already
 * uses (S14 B15). Auth failures early-return rather than abort() — same
 * posture LessonQuestions::toggleUpvote() already takes for a Livewire
 * action, not an HTTP endpoint.
 */
class LivePollResponse extends Component
{
    public BatchSession $session;

    public function mount(BatchSession $session): void
    {
        $this->session = $session;
    }

    /**
     * One response per student per poll — checked here first so a normal
     * click never surfaces a DB error, and caught again by the
     * live_poll_responses unique constraint (LivePollResponse model,
     * B13) as defense-in-depth against a genuine race between two
     * near-simultaneous submits, same "app check plus unique constraint"
     * shape LessonQuestionVote's own toggle already established.
     */
    public function submit(int $optionId): void
    {
        $viewer = auth()->user();
        $lesson = $this->session->lesson;

        if ($viewer === null || $lesson === null || ! app(LessonAccess::class)->canJoinLive($lesson, $this->session, $viewer)) {
            return;
        }

        $poll = LivePoll::where('batch_session_id', $this->session->id)->where('status', 'open')->first();

        if ($poll === null) {
            return;
        }

        $alreadyResponded = LivePollResponseModel::where('live_poll_id', $poll->id)
            ->where('user_id', $viewer->id)
            ->exists();

        if ($alreadyResponded) {
            return;
        }

        $option = $poll->options()->find($optionId);

        if ($option === null) {
            return;
        }

        try {
            LivePollResponseModel::create([
                'live_poll_id' => $poll->id,
                'live_poll_option_id' => $option->id,
                'user_id' => $viewer->id,
            ]);
        } catch (QueryException) {
            // Race lost to a near-simultaneous second submit — the first
            // write already recorded this user's vote, so this one is
            // silently dropped rather than surfacing a DB error.
        }
    }

    public function render(): View
    {
        $viewer = auth()->user();

        $poll = LivePoll::where('batch_session_id', $this->session->id)
            ->where('status', 'open')
            ->with('options')
            ->latest('id')
            ->first();

        $alreadyResponded = $poll !== null && $viewer !== null && LivePollResponseModel::where('live_poll_id', $poll->id)
            ->where('user_id', $viewer->id)
            ->exists();

        return view('livewire.live-poll-response', [
            'poll' => $poll,
            'alreadyResponded' => $alreadyResponded,
        ]);
    }
}
