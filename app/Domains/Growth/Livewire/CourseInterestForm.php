<?php

namespace App\Domains\Growth\Livewire;

use App\Domains\Content\Models\Course;
use App\Domains\Growth\Services\CourseInterestSignupService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * S20 R6 — public, unauthenticated (design doc §4 decision 8): a prospect
 * isn't a user yet, so there's no auth check here at all, unlike every
 * other Growth Livewire component this domain has (which all gate on
 * `auth()->user()`).
 */
class CourseInterestForm extends Component
{
    public int $courseId;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public bool $submitted = false;

    public function mount(int $courseId): void
    {
        $this->courseId = $courseId;
    }

    public function submit(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'phone' => 'nullable|string|max:30',
        ]);

        $course = Course::findOrFail($this->courseId);

        app(CourseInterestSignupService::class)->signup(
            $course,
            $this->name,
            $this->email,
            $this->phone !== '' ? $this->phone : null,
        );

        $this->submitted = true;
    }

    public function render(): View
    {
        return view('growth::course-interest-form');
    }
}
