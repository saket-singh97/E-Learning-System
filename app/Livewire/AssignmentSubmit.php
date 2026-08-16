<?php

namespace App\Livewire;

use App\Domains\Assignments\Models\Assignment;
use App\Domains\Assignments\Models\AssignmentSubmission;
use App\Domains\Assignments\Services\AssignmentSubmissionService;
use App\Exceptions\AssignmentNotAvailableException;
use App\Exceptions\AssignmentResubmissionLimitExceededException;
use App\Exceptions\InvalidFileTypeException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Student-facing submission UI (design doc §3.0), reached from the syllabus
 * tree's assignment rows via ShowAssignmentController — same mount shape
 * QuizRunner (S6 B5) established. Covers the whole submit/resubmit
 * lifecycle up to AssignmentSubmissionService::MAX_VERSIONS (B2 + B3, one
 * component, since the underlying service already unifies both actions —
 * see that class's own docblock).
 */
class AssignmentSubmit extends Component
{
    use WithFileUploads;

    public Assignment $assignment;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $files = [];

    public function mount(Assignment $assignment): void
    {
        $this->assignment = $assignment;
    }

    public function submit(): void
    {
        $this->resetErrorBag();

        $user = auth()->user();

        if ($user === null) {
            $this->addError('submission', 'Sign in to submit this assignment.');

            return;
        }

        $this->validate([
            'files' => ['required', 'array', 'min:1', 'max:5'],
            'files.*' => [
                'file',
                'max:'.$this->assignment->max_file_size_kb,
                'mimes:'.implode(',', $this->assignment->allowed_file_types),
            ],
        ]);

        try {
            app(AssignmentSubmissionService::class)->submit($this->assignment, $user, $this->files);
        } catch (AssignmentNotAvailableException) {
            $this->addError('submission', 'This assignment is not currently available.');

            return;
        } catch (AssignmentResubmissionLimitExceededException) {
            $this->addError('submission', 'You have used all 3 allowed submissions for this assignment.');

            return;
        } catch (InvalidFileTypeException $e) {
            $this->addError('files', $e->getMessage());

            return;
        }

        $this->files = [];
    }

    public function render(): View
    {
        $user = auth()->user();

        /** @var Collection<int, AssignmentSubmission> $history */
        $history = $user === null ? collect() : AssignmentSubmission::where('assignment_id', $this->assignment->id)
            ->where('user_id', $user->id)
            ->orderBy('version_number')
            ->get();

        return view('livewire.assignment-submit', [
            'history' => $history,
            'canSubmitAnotherVersion' => $history->count() < AssignmentSubmissionService::MAX_VERSIONS,
        ]);
    }
}
