<?php

namespace App\Domains\Content\Livewire;

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Services\CatchUpPlanService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Same "only an actively-enrolled student" gate DailyPracticeBoard (S22 B4)
 * already established for a per-batch student page — no existing
 * student-facing "batch" page to embed this on either, same "add a small
 * host route" shape.
 */
class CatchUpPlan extends Component
{
    public Batch $batch;

    /** @var Collection<int, Lesson> */
    public Collection $plan;

    public function mount(Batch $batch, CatchUpPlanService $service): void
    {
        $viewer = auth()->user();

        $isEnrolled = $viewer !== null && Enrolment::where('batch_id', $batch->id)
            ->where('user_id', $viewer->id)
            ->where('status', 'active')
            ->exists();

        abort_unless($isEnrolled, 404);

        $this->batch = $batch;
        $this->plan = $service->planFor($viewer, $batch);
    }

    public function render(): View
    {
        return view('content::catch-up-plan');
    }
}
