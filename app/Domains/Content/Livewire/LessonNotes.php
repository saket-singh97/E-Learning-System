<?php

namespace App\Domains\Content\Livewire;

use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\LessonNote;
use App\Domains\Content\Services\LessonAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Private per-user list (a student's own notes/bookmarks, not a shared
 * thread like LessonQuestions) — same "my own" scoping Wishlist already
 * established (B3). Gated by the same LessonAccess::canStream() verdict
 * every other lesson-attached action in this slice reuses (design doc
 * decision 8's own reasoning extends naturally here, even though decision
 * 8 itself is scoped to Q&A).
 */
class LessonNotes extends Component
{
    public Lesson $lesson;

    public string $newNoteBody = '';

    public function mount(Lesson $lesson): void
    {
        $this->lesson = $lesson;
    }

    public function notes(): Collection
    {
        $viewer = auth()->user();

        if (! $viewer) {
            return collect();
        }

        return LessonNote::where('lesson_id', $this->lesson->id)
            ->where('user_id', $viewer->id)
            ->orderBy('timestamp_s')
            ->get();
    }

    /**
     * A pure timestamp marker — body left null (design doc decision 7).
     */
    public function bookmark(int $timestampS): void
    {
        $viewer = auth()->user();

        if (! $viewer || ! app(LessonAccess::class)->canStream($this->lesson, $viewer)) {
            return;
        }

        LessonNote::create([
            'lesson_id' => $this->lesson->id,
            'user_id' => $viewer->id,
            'timestamp_s' => max(0, $timestampS),
        ]);
    }

    public function addNote(int $timestampS): void
    {
        $viewer = auth()->user();

        if (! $viewer || ! app(LessonAccess::class)->canStream($this->lesson, $viewer)) {
            return;
        }

        $this->validate(['newNoteBody' => 'required|string|max:2000']);

        LessonNote::create([
            'lesson_id' => $this->lesson->id,
            'user_id' => $viewer->id,
            'timestamp_s' => max(0, $timestampS),
            'body' => $this->newNoteBody,
        ]);

        $this->newNoteBody = '';
    }

    public function render(): View
    {
        return view('content::lesson-notes', ['notes' => $this->notes()]);
    }
}
