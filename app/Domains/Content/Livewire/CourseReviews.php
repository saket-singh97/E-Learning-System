<?php

namespace App\Domains\Content\Livewire;

use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseReview;
use App\Domains\Content\Policies\CourseReviewPolicy;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Enrolment-gated submit flow (design doc decision 12) — CourseReviewPolicy
 * is the single answer to "may this viewer review this course", reused
 * here rather than re-derived inline (same "one place answers this"
 * posture CLAUDE.md documents for LessonAccess). One review per learner
 * per course (course_reviews' own unique constraint) — re-submitting
 * updates the existing row rather than erroring.
 */
class CourseReviews extends Component
{
    public Course $course;

    public int $rating = 5;

    public string $body = '';

    /** @var array<int, string> */
    public array $responseDrafts = [];

    public function mount(Course $course): void
    {
        $this->course = $course;

        $existing = $this->myReview();

        if ($existing) {
            $this->rating = $existing->rating;
            $this->body = (string) $existing->body;
        }
    }

    /**
     * Published only — a hidden review (B15's own moderation flag) stays
     * out of the public list even though this block never sets that
     * status itself.
     */
    public function reviews(): Collection
    {
        return CourseReview::where('course_id', $this->course->id)
            ->where('status', 'published')
            ->with('user')
            ->orderByDesc('created_at')
            ->get();
    }

    public function myReview(): ?CourseReview
    {
        $viewer = auth()->user();

        if (! $viewer) {
            return null;
        }

        return CourseReview::where('course_id', $this->course->id)
            ->where('user_id', $viewer->id)
            ->first();
    }

    public function canReview(): bool
    {
        $viewer = auth()->user();

        return $viewer !== null && app(CourseReviewPolicy::class)->canReview($viewer, $this->course);
    }

    public function submit(): void
    {
        if (! $this->canReview()) {
            return;
        }

        $this->validate([
            'rating' => 'required|integer|min:1|max:5',
            'body' => 'nullable|string|max:2000',
        ]);

        CourseReview::updateOrCreate(
            ['course_id' => $this->course->id, 'user_id' => auth()->id()],
            ['rating' => $this->rating, 'body' => $this->body !== '' ? $this->body : null],
        );
    }

    /**
     * "Course's own instructor" has no backing per-course assignment model
     * to check (same gap EnrolmentApprovalPolicy's own docblock already
     * documents) — read as "an instructor in this course's own tenant",
     * the same same-tenant reading that policy already established.
     * Instructor-only, deliberately not institute_admin too: responding to
     * feedback and moderating abuse are different jobs (moderation is the
     * Admin-panel's own CourseReviewResource, B15's other half).
     */
    public function canRespond(): bool
    {
        $viewer = auth()->user();

        return $viewer !== null
            && $viewer->tenant_id === $this->course->tenant_id
            && $viewer->hasRole('instructor');
    }

    public function respond(int $reviewId): void
    {
        if (! $this->canRespond()) {
            return;
        }

        $this->validate(["responseDrafts.{$reviewId}" => 'required|string|max:2000']);

        $review = CourseReview::where('course_id', $this->course->id)->findOrFail($reviewId);

        $review->update([
            'instructor_response' => $this->responseDrafts[$reviewId],
            'instructor_response_at' => now(),
        ]);
    }

    public function render(): View
    {
        return view('content::course-reviews', [
            'reviews' => $this->reviews(),
            'canReview' => $this->canReview(),
            'canRespond' => $this->canRespond(),
        ]);
    }
}
