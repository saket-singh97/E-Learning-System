<?php

namespace App\Domains\Content\Services;

use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Same-category/same-tag heuristic only — not a real behavioural
 * recommender (design doc §2.3): this platform has zero production
 * enrolment-pattern volume to train one on yet. Both entry points share
 * excludeCourseIds()/relatedTo() rather than duplicating the "published,
 * shares taxonomy, not already enrolled" query twice.
 */
class RecommendationService
{
    protected const LIMIT = 6;

    /**
     * Courses related to a single course (its own category/tag overlap) —
     * the single-course page's own "you might also like" surface.
     */
    public function forCourse(Course $course, ?User $viewer): Collection
    {
        return $this->relatedTo(
            categoryIds: $course->categories()->pluck('categories.id')->all(),
            tagIds: $course->tags()->pluck('tags.id')->all(),
            excludeCourseIds: [$course->id, ...$this->enrolledCourseIds($viewer)],
        );
    }

    /**
     * Courses related to everything the viewer is already enrolled in —
     * the catalog page's own "recommended for you" surface. A guest, or a
     * student with no enrolments yet, has no taxonomy to base a
     * recommendation on, so this deliberately returns empty rather than
     * falling back to "popular courses" (a different, un-designed
     * heuristic, design doc §2.3's own "no glossing over" posture).
     */
    public function forViewer(?User $viewer): Collection
    {
        if (! $viewer) {
            return new Collection;
        }

        $enrolledCourseIds = $this->enrolledCourseIds($viewer);

        if ($enrolledCourseIds === []) {
            return new Collection;
        }

        $enrolledCourses = Course::whereIn('id', $enrolledCourseIds)->get();

        return $this->relatedTo(
            categoryIds: $enrolledCourses->flatMap(fn (Course $course) => $course->categories()->pluck('categories.id'))->unique()->all(),
            tagIds: $enrolledCourses->flatMap(fn (Course $course) => $course->tags()->pluck('tags.id'))->unique()->all(),
            excludeCourseIds: $enrolledCourseIds,
        );
    }

    /**
     * @param  array<int, int>  $categoryIds
     * @param  array<int, int>  $tagIds
     * @param  array<int, int>  $excludeCourseIds
     */
    protected function relatedTo(array $categoryIds, array $tagIds, array $excludeCourseIds): Collection
    {
        if ($categoryIds === [] && $tagIds === []) {
            return new Collection;
        }

        return Course::query()
            ->where('status', 'published')
            ->whereNotIn('id', $excludeCourseIds)
            ->where(fn ($query) => $query
                ->when($categoryIds !== [], fn ($query) => $query->whereHas(
                    'categories',
                    fn ($query) => $query->whereIn('categories.id', $categoryIds),
                ))
                ->when($tagIds !== [], fn ($query) => $query->orWhereHas(
                    'tags',
                    fn ($query) => $query->whereIn('tags.id', $tagIds),
                )))
            ->orderBy('title')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * `active`/`expired` only — a `rejected`/`pending_payment`/
     * `pending_approval`/`cancelled`/`waitlisted` enrolment never became
     * real participation, so it shouldn't feed the "recommended for you"
     * taxonomy basis, nor exclude a course the viewer never actually
     * attended (found by S13 B17's review pass, same status-blindness
     * class as the original CourseReviewPolicy gap).
     *
     * @return array<int, int>
     */
    protected function enrolledCourseIds(?User $viewer): array
    {
        if (! $viewer) {
            return [];
        }

        return Enrolment::where('user_id', $viewer->id)
            ->whereIn('status', ['active', 'expired'])
            ->with('batch')
            ->get()
            ->pluck('batch.course_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
