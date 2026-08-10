<?php

namespace App\Domains\Batches\Livewire;

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Batches\Services\BatchEnrolmentService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class BatchCatalog extends Component
{
    public function join(int $batchId): void
    {
        $viewer = auth()->user();

        if (! $viewer) {
            return;
        }

        $batch = Batch::findOrFail($batchId);

        if ($batch->self_enrol_mode === 'manual_only') {
            return;
        }

        app(BatchEnrolmentService::class)->enrol($viewer, $batch);
    }

    public function render(): View
    {
        $service = app(BatchEnrolmentService::class);
        $viewer = auth()->user();

        $rows = Batch::where('status', 'published')
            ->whereHas('course', fn ($query) => $query->where('status', 'published'))
            ->with('course')
            ->get()
            ->map(function (Batch $batch) use ($service, $viewer) {
                $enrolment = $viewer
                    ? Enrolment::where('batch_id', $batch->id)->where('user_id', $viewer->id)->first()
                    : null;

                return [
                    'batch' => $batch,
                    'seatsRemaining' => $service->seatsRemaining($batch),
                    'enrolment' => $enrolment,
                    'waitlistPosition' => $enrolment?->status === 'waitlisted'
                        ? $this->waitlistPosition($enrolment)
                        : null,
                ];
            });

        return view('batches::batch-catalog', ['rows' => $rows]);
    }

    /**
     * FIFO position via created_at, same ordering BatchEnrolmentService::
     * promoteNextWaitlisted() promotes by — no separate position column to
     * keep in sync (design doc §3.2). id breaks a created_at tie the same
     * way promoteNextWaitlisted() does: enrolments.created_at is whole-second
     * precision in Postgres, so two joins in the same second need id (insert
     * order) to keep the displayed position from misleading either student.
     */
    protected function waitlistPosition(Enrolment $enrolment): int
    {
        return Enrolment::where('batch_id', $enrolment->batch_id)
            ->where('status', 'waitlisted')
            ->where(function ($query) use ($enrolment) {
                $query->where('created_at', '<', $enrolment->created_at)
                    ->orWhere(function ($query) use ($enrolment) {
                        $query->where('created_at', $enrolment->created_at)
                            ->where('id', '<', $enrolment->id);
                    });
            })
            ->count() + 1;
    }
}
