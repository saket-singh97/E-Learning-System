<?php

namespace App\Domains\AI\Livewire;

use App\Domains\AI\Services\ChapterFormulaSheetReader;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Services\LessonAccess;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * U5 — gated the same course-level way the syllabus page/catalog already
 * are (LessonAccess::isCourseVisibleTo(), CLAUDE.md: the one class every
 * visibility check must go through), not a per-lesson streaming gate — a
 * revision card is chapter-level review content, same sensitivity as the
 * syllabus tree it's reached from.
 */
class FormulaSheet extends Component
{
    public CourseSection $section;

    /** @var array<int, string> */
    public array $concepts = [];

    public function mount(CourseSection $section, LessonAccess $access, ChapterFormulaSheetReader $reader): void
    {
        $course = $section->course;

        abort_unless($course !== null && $access->isCourseVisibleTo($course, auth()->user()), 404);

        $this->section = $section;
        $this->concepts = $reader->forSection($section);
    }

    public function render(): View
    {
        return view('ai::formula-sheet');
    }
}
