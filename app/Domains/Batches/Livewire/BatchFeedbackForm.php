<?php

namespace App\Domains\Batches\Livewire;

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchFeedback;
use App\Domains\Batches\Policies\BatchFeedbackPolicy;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Enrolment-gated submit flow (F8 pt.1), same "one place answers this"
 * shape CourseReviews already established for a different entity —
 * BatchFeedbackPolicy is the single answer to "may this viewer submit
 * feedback for this batch". One row per learner per batch (batch_
 * feedback's own unique constraint) — re-submitting updates the existing
 * row rather than erroring.
 */
class BatchFeedbackForm extends Component
{
    public Batch $batch;

    public int $rating = 5;

    public string $comment = '';

    public function mount(Batch $batch): void
    {
        $this->batch = $batch;

        $existing = $this->myFeedback();

        if ($existing) {
            $this->rating = $existing->rating;
            $this->comment = (string) $existing->comment;
        }
    }

    public function myFeedback(): ?BatchFeedback
    {
        $viewer = auth()->user();

        if (! $viewer) {
            return null;
        }

        return BatchFeedback::where('batch_id', $this->batch->id)
            ->where('user_id', $viewer->id)
            ->first();
    }

    public function canSubmit(): bool
    {
        $viewer = auth()->user();

        return $viewer !== null && app(BatchFeedbackPolicy::class)->canSubmit($viewer, $this->batch);
    }

    public function submit(): void
    {
        if (! $this->canSubmit()) {
            return;
        }

        $this->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
        ]);

        BatchFeedback::updateOrCreate(
            ['batch_id' => $this->batch->id, 'user_id' => auth()->id()],
            ['rating' => $this->rating, 'comment' => $this->comment !== '' ? $this->comment : null],
        );
    }

    public function render(): View
    {
        return view('batches::batch-feedback-form', [
            'canSubmit' => $this->canSubmit(),
        ]);
    }
}
