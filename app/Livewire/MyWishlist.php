<?php

namespace App\Livewire;

use App\Domains\Content\Models\Course;
use App\Domains\Content\Services\WishlistService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * No route-level auth gate — this app has no single named 'login' route
 * (3 separate Filament panel logins + an embedded StudentPortal, not a
 * routed page) for Laravel's default Authenticate middleware to redirect a
 * guest to, so a hard gate 500s instead of redirecting. Guest gets an empty
 * list + prompt instead, same no-op-for-guest posture CourseCatalog/
 * BatchCatalog already established for their own guest visitors.
 */
class MyWishlist extends Component
{
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

        $courses = $viewer
            ? Course::query()
                ->whereHas('wishlists', fn ($query) => $query->where('user_id', $viewer->id))
                ->orderBy('title')
                ->get()
            : collect();

        return view('livewire.my-wishlist', ['courses' => $courses, 'viewer' => $viewer]);
    }
}
