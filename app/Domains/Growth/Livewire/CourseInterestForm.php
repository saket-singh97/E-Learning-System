<?php

namespace App\Domains\Growth\Livewire;

use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\Lesson;
use App\Domains\Growth\Models\CourseInterestSignup;
use App\Domains\Growth\Services\CourseInterestSignupService;
use App\Support\Tenancy\CurrentTenant;
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

    /**
     * S23 B11 (U13 pt.2, design doc §4 decision 3) — recorded here, inside
     * this same public interest-capture form, rather than the anonymous
     * lesson-watch page itself: this component already knows the
     * prospect's own email (it just signed them up), so marking
     * `demo_attended_at` needs no separate identity-carrying mechanism
     * across an unauthenticated page boundary. One click both records
     * attendance and hands the guest off to the actual watch page — the
     * same fully-working, type-aware rendering B10 already built, not
     * duplicated here.
     */
    public function watchDemo(): void
    {
        if (! $this->submitted) {
            return;
        }

        $demoLesson = $this->demoLesson();

        if ($demoLesson === null) {
            return;
        }

        CourseInterestSignup::where('course_id', $this->courseId)
            ->where('email', $this->email)
            ->whereNull('demo_attended_at')
            ->update(['demo_attended_at' => now()]);

        $this->redirect(route('lessons.watch', [
            'subdomain' => app(CurrentTenant::class)->get()->subdomain,
            'lesson' => $demoLesson->id,
        ]));
    }

    protected function demoLesson(): ?Lesson
    {
        $course = Course::find($this->courseId);

        if ($course === null) {
            return null;
        }

        return Lesson::whereIn('course_section_id', $course->sections()->pluck('id'))
            ->where('is_demo', true)
            ->first();
    }

    public function render(): View
    {
        return view('growth::course-interest-form', [
            'demoLesson' => $this->submitted ? $this->demoLesson() : null,
        ]);
    }
}
