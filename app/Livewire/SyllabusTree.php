<?php

namespace App\Livewire;

use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assignments\Models\Assignment;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Services\LessonAccess;
use App\Domains\Content\Services\SyllabusTracker;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class SyllabusTree extends Component
{
    public Course $course;

    public function mount(Course $course): void
    {
        $this->course = $course;
    }

    public function render(): View
    {
        $access = app(LessonAccess::class);
        $viewer = auth()->user();

        $sections = $this->course->sections()
            ->with('lessons')
            ->get()
            ->map(fn (CourseSection $section): array => [
                'section' => $section,
                'rows' => $section->lessons
                    ->filter(fn (Lesson $lesson): bool => $access->isVisibleTo($lesson, $viewer))
                    ->map(function (Lesson $lesson) use ($access, $viewer): array {
                        // myLiveBatchSession() resolves *which* session is this
                        // viewer's own; canJoinLive() is the one true gate on
                        // top of it (design doc §3.3) — kept as two calls
                        // rather than folding the enrolment check in here a
                        // second time, so this view can never drift from the
                        // join endpoint's own authorization (see LessonAccess's
                        // own class docblock for why that split matters).
                        $session = $lesson->type === 'live_session'
                            ? $access->myLiveBatchSession($lesson, $viewer)
                            : null;

                        // Quiz::isVisibleTo() is Assessments' own gate (not
                        // LessonAccess's — see that method's docblock for why
                        // it isn't folded in there), read directly since this
                        // component sits outside every domain namespace, the
                        // same posture it already has toward Batches\Enrolment
                        // via myLiveBatchSession() above.
                        $quiz = $lesson->type === 'quiz'
                            ? Quiz::where('lesson_id', $lesson->id)->latest('id')->first()
                            : null;

                        // Same posture as the $quiz resolution just above —
                        // Assignment::isVisibleTo() is this domain's own
                        // gate (not LessonAccess's), read directly since
                        // this component sits outside every domain
                        // namespace already.
                        $assignment = $lesson->type === 'assignment'
                            ? Assignment::where('lesson_id', $lesson->id)->latest('id')->first()
                            : null;

                        return [
                            'lesson' => $lesson,
                            'session' => $session,
                            'liveState' => $session?->liveState(),
                            'canJoin' => $session !== null && $access->canJoinLive($lesson, $session, $viewer),
                            'canStream' => $access->canStream($lesson, $viewer),
                            'quiz' => $quiz,
                            'canAttemptQuiz' => $quiz !== null && $quiz->isVisibleTo($viewer),
                            'assignment' => $assignment,
                            'canSubmitAssignment' => $assignment !== null && $assignment->isVisibleTo($viewer),
                        ];
                    })
                    ->values(),
            ]);

        return view('livewire.syllabus-tree', [
            'sections' => $sections,
            'subdomain' => app(CurrentTenant::class)->get()->subdomain,
            // A guest previewing a published course's syllabus has no
            // completion signal to compute — percentCompleteFor() requires
            // an authenticated User.
            'syllabusTracker' => $viewer !== null ? app(SyllabusTracker::class)->percentCompleteFor($this->course, $viewer) : [],
        ]);
    }
}
