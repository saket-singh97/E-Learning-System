<?php

namespace App\Domains\AI\Livewire;

use App\Domains\AI\Models\LessonRevisionNote;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Services\LessonAccess;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * L11 pt.2 — student-facing revision notes view. Gated through LessonAccess
 * (CLAUDE.md: the one class every visibility check must go through) —
 * notes for a lesson the viewer can't already see must never leak, same
 * posture the stream route/player/heartbeat/doubt-chat all already take.
 * A component-level gate, not just relying on the host watch page's own
 * controller check, since Livewire::test() bypasses that route entirely
 * (CLAUDE.md's own warning about this exact gap).
 */
class LessonRevisionNotes extends Component
{
    public Lesson $lesson;

    public ?LessonRevisionNote $note = null;

    public function mount(Lesson $lesson, LessonAccess $access): void
    {
        abort_unless($access->isVisibleTo($lesson, auth()->user()), 404);

        $this->lesson = $lesson;
        $this->note = LessonRevisionNote::latestReadyFor($lesson->id);
    }

    public function render(): View
    {
        return view('ai::lesson-revision-notes');
    }
}
