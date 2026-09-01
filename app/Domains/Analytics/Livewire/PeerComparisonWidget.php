<?php

namespace App\Domains\Analytics\Livewire;

use App\Domains\AI\Services\WeakTopicDetectionService;
use App\Domains\Analytics\Services\PeerComparisonService;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * S24 U24 pt.2 — "you're in the top N% of your batch on {concept}", one
 * row per one of the *viewer's own* weak topics (`WeakTopicDetectionService::weakTopicsFor()`'s
 * own per-student list is the concept set shown here, same reuse the plan
 * names) — never a classmate's name or score, same "own standing only"
 * posture `TestSeriesResults` (S16 B13) already established, and
 * structurally the same way: `weakTopicsFor()`/`percentileFor()` are both
 * already scoped to the viewer, there's no classmate row this component
 * could even reach. Embedded on the same course-scoped syllabus page
 * `ai.weak-topics` already lives on (design §2.3) — the concept set comes
 * from that exact same service call.
 *
 * `PeerComparisonService::percentileFor()` returns "beat X%" (S24 B8);
 * "top N%" is the complement, `100 - percentile`. A concept with no peer
 * group to compare against (B8's own `null` return) is simply omitted
 * from this list, not shown as an error or a misleading 0%/100%.
 */
class PeerComparisonWidget extends Component
{
    public Course $course;

    public function mount(Course $course): void
    {
        $this->course = $course;
    }

    public function render(): View
    {
        $viewer = auth()->user();
        $rows = collect();

        if ($viewer !== null) {
            $batch = $this->activeBatchFor($viewer);

            if ($batch !== null) {
                $rows = app(WeakTopicDetectionService::class)
                    ->weakTopicsFor($viewer, $this->course->id)
                    ->map(function (array $topic) use ($viewer, $batch): ?array {
                        $percentile = app(PeerComparisonService::class)->percentileFor($viewer, $batch, $topic['concept']);

                        return $percentile === null ? null : [
                            'concept' => $topic['concept'],
                            'top_percent' => round(100 - $percentile, 1),
                        ];
                    })
                    ->filter()
                    ->values();
            }
        }

        return view('analytics::peer-comparison-widget', ['rows' => $rows]);
    }

    protected function activeBatchFor(User $viewer): ?Batch
    {
        $batchId = Enrolment::where('user_id', $viewer->id)
            ->where('status', 'active')
            ->whereHas('batch', fn ($query) => $query->where('course_id', $this->course->id))
            ->value('batch_id');

        return $batchId === null ? null : Batch::find($batchId);
    }
}
