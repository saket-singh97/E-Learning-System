<?php

namespace App\Livewire;

use App\Domains\Content\Models\Category;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\Tag;
use App\Domains\Content\Models\Wishlist;
use App\Domains\Content\Services\LessonAccess;
use App\Domains\Content\Services\RecommendationService;
use App\Domains\Content\Services\WishlistService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

/**
 * Public, unauthenticated catalog — same posture as ShowSyllabusController's
 * own visibility rule (published courses are readable by anyone), not
 * gated behind student login. Category/tag are an AND filter (both applied
 * together when both are set), matching how a catalog filter sidebar reads
 * to a user ("in this category AND tagged this").
 */
class CourseCatalog extends Component
{
    public ?int $categoryId = null;

    public ?int $tagId = null;

    public string $search = '';

    /**
     * Reuses LessonAccess::isStaff() rather than a bare
     * `where('status', 'published')` — the review pass found the earlier
     * version of this method had silently dropped the staff-preview
     * bypass isCourseVisibleTo() already establishes for the syllabus
     * page, so an instructor/institute_admin couldn't see their own
     * unpublished course in the catalog they'd otherwise expect to
     * preview it in.
     */
    public function courses(): Collection
    {
        $viewer = auth()->user();

        return Course::query()
            ->when(! app(LessonAccess::class)->isStaff($viewer), fn ($query) => $query->where('status', 'published'))
            ->when($this->categoryId, fn ($query, $categoryId) => $query->whereHas(
                'categories',
                fn ($query) => $query->whereKey($categoryId),
            ))
            ->when($this->tagId, fn ($query, $tagId) => $query->whereHas(
                'tags',
                fn ($query) => $query->whereKey($tagId),
            ))
            // Eloquent orWhere/like, not whereRaw/DB::raw — SecurityBaselineTest
            // enforces the no-raw-SQL rule repo-wide (design doc §2.3).
            ->when($this->search, fn ($query, $search) => $query->where(
                fn ($query) => $query
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%"),
            ))
            ->orderBy('title')
            ->get();
    }

    /**
     * No-op for a guest — same "auth()->user() ?: return" posture
     * BatchCatalog::join() already established, not a hidden button plus a
     * route-level auth gate.
     */
    public function toggleWishlist(int $courseId): void
    {
        $viewer = auth()->user();

        if (! $viewer) {
            return;
        }

        app(WishlistService::class)->toggle($viewer, Course::findOrFail($courseId));
    }

    public function render(): View
    {
        $viewer = auth()->user();

        return view('livewire.course-catalog', [
            'courses' => $this->courses(),
            'categories' => Category::query()->orderBy('name')->get(),
            'tags' => Tag::query()->orderBy('name')->get(),
            'wishlistedCourseIds' => $viewer
                ? Wishlist::where('user_id', $viewer->id)->pluck('course_id')->all()
                : [],
            'recommendations' => app(RecommendationService::class)->forViewer($viewer),
        ]);
    }
}
