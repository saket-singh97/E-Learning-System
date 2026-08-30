<?php

namespace App\Domains\Growth\Livewire;

use App\Domains\Growth\Models\ScholarshipTest;
use App\Domains\Growth\Services\ScholarshipTestRegistrationService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * S23 B12 (U12 pt.1) — public, unauthenticated, same posture
 * CourseInterestForm already established (design doc §4): a prospect isn't
 * a user yet, so there's no auth check here at all.
 */
class ScholarshipTestRegistrationForm extends Component
{
    public int $scholarshipTestId;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public bool $submitted = false;

    public function mount(int $scholarshipTestId): void
    {
        $this->scholarshipTestId = $scholarshipTestId;
    }

    public function submit(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'phone' => 'nullable|string|max:30',
        ]);

        $test = ScholarshipTest::findOrFail($this->scholarshipTestId);

        app(ScholarshipTestRegistrationService::class)->register(
            $test,
            $this->name,
            $this->email,
            $this->phone !== '' ? $this->phone : null,
        );

        $this->submitted = true;
    }

    public function render(): View
    {
        return view('growth::scholarship-test-registration-form');
    }
}
