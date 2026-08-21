<?php

namespace App\Livewire;

use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\LivePoll;
use App\Domains\Batches\Services\LivePollService;
use App\Domains\Content\Services\LessonAccess;
use App\Exceptions\LivePollAuthorizationException;
use App\Exceptions\LivePollStateException;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Instructor launch controls + shared instructor/student result view
 * (design doc G9 pt.4) — the UI on top of LivePollService (B14, service
 * only, no UI of its own) and the aggregation display neither B14 nor B15
 * built. Sits on the syllabus tree's live_session row alongside
 * LivePollResponse (B15, the student vote form) — this component nests
 * that one for the open-and-not-yet-responded case rather than
 * duplicating its submit logic.
 *
 * Manage-controls visibility uses LessonAccess::isStaff() (same "one place
 * answers this" reuse posture every other staff-gated view already takes)
 * — the real authorization is LivePollService's own
 * EnrolmentApprovalPolicy::canDecide() check, run again server-side when
 * an action actually fires, same UI-visibility-vs-server-authorization
 * split canJoinLive()'s own Join link already has.
 */
class LivePollBoard extends Component
{
    public BatchSession $session;

    public string $question = '';

    /** @var array<int, string> */
    public array $optionInputs = ['', ''];

    public function mount(BatchSession $session): void
    {
        $this->session = $session;
    }

    public function addOption(): void
    {
        if (count($this->optionInputs) < 6) {
            $this->optionInputs[] = '';
        }
    }

    public function removeOption(int $index): void
    {
        if (count($this->optionInputs) > 2) {
            unset($this->optionInputs[$index]);
            $this->optionInputs = array_values($this->optionInputs);
        }
    }

    public function createPoll(): void
    {
        $viewer = auth()->user();

        if ($viewer === null) {
            return;
        }

        $labels = array_values(array_filter(
            array_map('trim', $this->optionInputs),
            fn (string $label): bool => $label !== '',
        ));

        if (trim($this->question) === '' || count($labels) < 2) {
            return;
        }

        try {
            app(LivePollService::class)->create($this->session, $viewer, trim($this->question), $labels);
        } catch (LivePollAuthorizationException) {
            return;
        }

        $this->question = '';
        $this->optionInputs = ['', ''];
    }

    public function openPoll(int $pollId): void
    {
        $this->runPollTransition($pollId, fn (LivePollService $service, LivePoll $poll, $viewer) => $service->open($poll, $viewer));
    }

    public function closePoll(int $pollId): void
    {
        $this->runPollTransition($pollId, fn (LivePollService $service, LivePoll $poll, $viewer) => $service->close($poll, $viewer));
    }

    /**
     * Both open/close share the same "swallow the expected refusal, let
     * the next render show the actual current state" posture
     * LivePollResponse::submit() already established for a Livewire
     * action — a stale button (e.g. two instructor tabs open) just
     * silently no-ops rather than surfacing a raw exception.
     */
    protected function runPollTransition(int $pollId, callable $action): void
    {
        $viewer = auth()->user();
        $poll = LivePoll::find($pollId);

        if ($viewer === null || $poll === null) {
            return;
        }

        try {
            $action(app(LivePollService::class), $poll, $viewer);
        } catch (LivePollAuthorizationException|LivePollStateException) {
            // Expected refusal — no-op, next render shows the real state.
        }
    }

    public function render(): View
    {
        $viewer = auth()->user();

        $poll = LivePoll::where('batch_session_id', $this->session->id)
            ->with('options.responses')
            ->latest('id')
            ->first();

        return view('livewire.live-poll-board', [
            'poll' => $poll,
            'isStaff' => app(LessonAccess::class)->isStaff($viewer),
            'totalResponses' => $poll?->options->sum(fn ($option) => $option->responses->count()) ?? 0,
        ]);
    }
}
