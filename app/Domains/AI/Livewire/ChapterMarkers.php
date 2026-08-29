<?php

namespace App\Domains\AI\Livewire;

use App\Domains\AI\Services\ChapterMarkerReader;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Services\LessonAccess;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * U2 — player-facing chapter markers. Gated through LessonAccess, same
 * posture LessonRevisionNotes already takes (CLAUDE.md: the one class
 * every visibility check must go through; Livewire::test() bypasses the
 * watch route's own controller check).
 */
class ChapterMarkers extends Component
{
    public Lesson $lesson;

    /** @var array<int, array{label: string, start_s: float}> */
    public array $markers = [];

    public function mount(Lesson $lesson, LessonAccess $access, ChapterMarkerReader $reader): void
    {
        abort_unless($access->isVisibleTo($lesson, auth()->user()), 404);

        $this->lesson = $lesson;
        $this->markers = $reader->forLesson($lesson);
    }

    public function render(): View
    {
        return view('ai::chapter-markers');
    }
}
