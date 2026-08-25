<?php

namespace App\Domains\AI\Livewire;

use App\Domains\AI\Services\WeakTopicDetectionService;
use App\Domains\Content\Models\Course;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * L13 pt.2 — student-facing weak-topics view, embedded on the existing
 * per-course syllabus page (`content.syllabus`) — course-scoped content is
 * the natural fit for a course-scoped service, no new page needed.
 *
 * Shows only the viewer's own weak concepts, never a classmate's — same
 * privacy posture S16 B13's `TestSeriesResults` already established for
 * rank/percentile (own row only, never the full roster). Structurally
 * guaranteed here, not just convention: `WeakTopicDetectionService`
 * already scopes every query to `auth()->user()->id`, so there is no
 * classmate data this component could even reach.
 */
class WeakTopics extends Component
{
    public Course $course;

    public function mount(Course $course): void
    {
        $this->course = $course;
    }

    public function render(): View
    {
        $viewer = auth()->user();

        $weakTopics = $viewer !== null
            ? app(WeakTopicDetectionService::class)->weakTopicsFor($viewer, $this->course->id)
            : collect();

        return view('ai::weak-topics', ['weakTopics' => $weakTopics]);
    }
}
