<?php

namespace App\Domains\AI\Livewire;

use App\Domains\AI\Models\PhotoDoubtQuery;
use App\Domains\AI\Services\PhotoDoubtService;
use App\Exceptions\InvalidFileTypeException;
use App\Exceptions\PhotoTooLargeException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * L14 pt.2 — student-facing upload UI. Plain file input (design §4
 * decision 7 — no native camera capture). `wire:poll` only renders while a
 * query is genuinely pending/processing, same pragmatic "poll only while
 * in flight" shape `FeeCheckout`'s own payment-status poll already
 * established (S9 B8 — no broadcasting infrastructure exists yet).
 */
class PhotoDoubtUpload extends Component
{
    use WithFileUploads;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $image = null;

    public ?int $courseId = null;

    public function submit(): void
    {
        $this->resetErrorBag();

        $user = auth()->user();

        if ($user === null) {
            $this->addError('image', 'Sign in to ask a photo doubt.');

            return;
        }

        $this->validate([
            'image' => ['required', 'mimes:'.implode(',', PhotoDoubtService::ALLOWED_EXTENSIONS)],
        ]);

        try {
            app(PhotoDoubtService::class)->submit($this->image, $user, $this->courseId);
        } catch (InvalidFileTypeException|PhotoTooLargeException $e) {
            $this->addError('image', $e->getMessage());

            return;
        }

        $this->image = null;
    }

    public function render(): View
    {
        $user = auth()->user();

        /** @var Collection<int, PhotoDoubtQuery> $queries */
        $queries = $user === null
            ? collect()
            : PhotoDoubtQuery::where('user_id', $user->id)->latest()->get();

        return view('ai::photo-doubt-upload', ['queries' => $queries]);
    }
}
