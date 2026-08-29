<?php

namespace App\Domains\AI\Livewire;

use App\Domains\AI\Services\ChapterFormulaSheetReader;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Services\LessonAccess;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * U5 — page-level gate is course visibility (LessonAccess::isCourseVisibleTo(),
 * CLAUDE.md: the one class every visibility check must go through), same as
 * the syllabus tree — a guest can load this page for any published course,
 * same as they can load the syllabus. But unlike the syllabus tree (author-
 * written titles only), this renders real mined lecture content, so
 * ChapterFormulaSheetReader additionally gates *per lesson* on
 * isVisibleTo()+canStream() (S22 B13 review pass) — a lesson this viewer
 * can't actually stream contributes nothing, same posture DoubtChatService/
 * both stream controllers already take.
 */
class FormulaSheet extends Component
{
    public CourseSection $section;

    /** @var array<int, string> */
    public array $concepts = [];

    public function mount(CourseSection $section, LessonAccess $access, ChapterFormulaSheetReader $reader): void
    {
        $course = $section->course;
        $viewer = auth()->user();

        abort_unless($course !== null && $access->isCourseVisibleTo($course, $viewer), 404);

        $this->section = $section;
        $this->concepts = $reader->forSection($section, $access, $viewer);
    }

    public function render(): View
    {
        return view('ai::formula-sheet');
    }
}
