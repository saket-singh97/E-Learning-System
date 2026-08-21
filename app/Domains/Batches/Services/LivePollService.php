<?php

namespace App\Domains\Batches\Services;

use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\LivePoll;
use App\Domains\Batches\Models\LivePollOption;
use App\Domains\Batches\Policies\EnrolmentApprovalPolicy;
use App\Exceptions\LivePollAuthorizationException;
use App\Exceptions\LivePollStateException;
use App\Models\User;
use InvalidArgumentException;

/**
 * Instructor-side poll lifecycle (design doc G9 pt.2) — create (draft) ->
 * open -> closed. Reuses EnrolmentApprovalPolicy::canDecide() rather than a
 * new instructor/institute_admin constant (design doc decision: "same role
 * gate EnrolmentApprovalPolicy::DECIDER_ROLES already uses"), same
 * "authorization question, not a state-transition one" split
 * EnrolmentsRelationManager::authorizedActor() already established — this
 * service still checks it itself, though, since no UI boundary exists yet
 * to check it upstream (B14 ships the service only; B15/B16 build the
 * Livewire UI on top of it).
 */
class LivePollService
{
    public function create(BatchSession $session, User $actor, string $question, array $optionLabels): LivePoll
    {
        $this->assertCanManage($session, $actor);

        if (count($optionLabels) < 2) {
            throw new InvalidArgumentException('A live poll needs at least 2 options.');
        }

        $poll = LivePoll::create([
            'batch_session_id' => $session->id,
            'question' => $question,
            'status' => 'draft',
        ]);

        foreach (array_values($optionLabels) as $position => $label) {
            LivePollOption::create([
                'live_poll_id' => $poll->id,
                'label' => $label,
                'position' => $position,
            ]);
        }

        return $poll->fresh('options');
    }

    /**
     * Gated to a currently-live session, not just draft status — the same
     * STATE_LIVE window LessonAccess::canJoinLive() already requires for a
     * student to even reach the join button, so a poll opened outside it
     * would have no one able to respond anyway.
     */
    public function open(LivePoll $poll, User $actor): void
    {
        $session = $poll->batchSession;
        $this->assertCanManage($session, $actor);

        if ($poll->status !== 'draft') {
            throw LivePollStateException::cannotOpen($poll->id, $poll->status);
        }

        if ($session->liveState() !== BatchSession::STATE_LIVE) {
            throw LivePollStateException::sessionNotLive($session->id);
        }

        $poll->update(['status' => 'open', 'opened_at' => now()]);
    }

    public function close(LivePoll $poll, User $actor): void
    {
        $this->assertCanManage($poll->batchSession, $actor);

        if ($poll->status !== 'open') {
            throw LivePollStateException::cannotClose($poll->id, $poll->status);
        }

        $poll->update(['status' => 'closed', 'closed_at' => now()]);
    }

    protected function assertCanManage(BatchSession $session, User $actor): void
    {
        if (! app(EnrolmentApprovalPolicy::class)->canDecide($actor, $session->batch)) {
            throw LivePollAuthorizationException::forActor($actor->id, $session->id);
        }
    }
}
