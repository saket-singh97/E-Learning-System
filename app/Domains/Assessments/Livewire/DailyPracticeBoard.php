<?php

namespace App\Domains\Assessments\Livewire;

use App\Domains\Assessments\Models\DailyPracticeSet;
use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Services\DailyPracticeSetGenerator;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * U3 pt.2 — on-demand generation at first visit (design doc B4 checklist:
 * a daily sweep would build a set for every batch even when no student
 * opens it that day). DailyPracticeSetGenerator::generateFor() is already
 * idempotent, so calling it on every mount is safe. Same "only an
 * actively-enrolled student" gate GeofenceCheckIn already established for
 * a per-batch student page.
 *
 * Questions render directly (not wrapped in a Quiz/attempt): they're
 * draft/ai_generated like U6's own quiz questions, but DPP is ungraded
 * self-practice, not an attempt QuizAttemptService would draw from its
 * approved-only pool anyway — wrapping in Quiz here would just render an
 * empty attempt until an instructor reviews every question.
 */
class DailyPracticeBoard extends Component
{
    public Batch $batch;

    public ?DailyPracticeSet $set = null;

    /** @var Collection<int, Question> */
    public Collection $questions;

    public function mount(Batch $batch, DailyPracticeSetGenerator $generator): void
    {
        $viewer = auth()->user();

        $isEnrolled = $viewer !== null && Enrolment::where('batch_id', $batch->id)
            ->where('user_id', $viewer->id)
            ->where('status', 'active')
            ->exists();

        abort_unless($isEnrolled, 404);

        $this->batch = $batch;
        $this->set = $generator->generateFor($batch, now());
        $this->questions = Question::whereIn('id', $this->set->question_ids)->get();
    }

    public function render(): View
    {
        return view('assessments::daily-practice-board');
    }
}
