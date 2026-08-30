<?php

namespace App\Domains\Assessments\Livewire;

use App\Domains\Assessments\Models\Question;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Services\LessonAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * U11 pt.2 — same "only an actively-enrolled viewer" gate DailyPracticeBoard
 * (S22 B4) / CatchUpPlan (S23 B2) already established, scoped to the course
 * rather than a batch since Question belongs to a course, not a batch
 * (design doc §3.2). A PYQ seeded from a specific lesson (lesson_id set)
 * also re-checks LessonAccess::canStream() on that lesson — a PYQ tied to a
 * still-locked lesson (prerequisite not yet complete) stays hidden, same
 * gate LessonQuestions already re-applies rather than trusting page-load
 * alone (CLAUDE.md's own LessonAccess chokepoint rule).
 */
class PyqBrowser extends Component
{
    public Course $course;

    public ?string $concept = null;

    public ?string $examName = null;

    public ?int $examYear = null;

    public function mount(Course $course): void
    {
        $viewer = auth()->user();

        $isEnrolled = $viewer !== null && Enrolment::where('user_id', $viewer->id)
            ->where('status', 'active')
            ->whereHas('batch', fn ($query) => $query->where('course_id', $course->id))
            ->exists();

        abort_unless($isEnrolled, 404);

        $this->course = $course;
    }

    /** @return Collection<int, Question> */
    public function questions(LessonAccess $lessonAccess): Collection
    {
        $viewer = auth()->user();

        return Question::where('course_id', $this->course->id)
            ->where('is_pyq', true)
            ->where('status', 'approved')
            ->when($this->concept, fn ($query, $concept) => $query->where('concept', $concept))
            ->when($this->examName, fn ($query, $examName) => $query->where('pyq_exam_name', $examName))
            ->when($this->examYear, fn ($query, $examYear) => $query->where('pyq_exam_year', $examYear))
            ->with('lesson')
            ->get()
            ->filter(fn (Question $question) => $question->lesson === null || $lessonAccess->canStream($question->lesson, $viewer))
            ->values();
    }

    public function render(LessonAccess $lessonAccess): View
    {
        return view('assessments::pyq-browser', [
            'questions' => $this->questions($lessonAccess),
        ]);
    }
}
