<?php

namespace App\Domains\Analytics\Livewire;

use App\Domains\Analytics\Models\GamificationProfile;
use App\Domains\Analytics\Models\StudentBadge;
use App\Domains\Analytics\Services\LeaderboardService;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * S19 N18 pt.2 — the viewer's own profile/badges are always visible; the
 * per-batch leaderboard is the only part gated by the tenant-level opt-out
 * (design doc §4 decision 9). No `GamificationProfile` row yet (no XP
 * awarded so far) reads as the same defaults `GamificationService` would
 * create on first award — a viewer with zero activity sees "level 1, 0 XP",
 * not an error.
 */
class GamificationWidget extends Component
{
    public ?int $batchId = null;

    public function mount(): void
    {
        $this->batchId = $this->batches()->first()?->id;
    }

    public function profile(): GamificationProfile
    {
        $viewer = auth()->user();

        return ($viewer ? GamificationProfile::where('user_id', $viewer->id)->first() : null)
            ?? new GamificationProfile(['xp_total' => 0, 'level' => 1, 'current_streak_days' => 0, 'longest_streak_days' => 0]);
    }

    public function badges(): Collection
    {
        $viewer = auth()->user();

        if (! $viewer) {
            return collect();
        }

        return StudentBadge::with('badge')->where('user_id', $viewer->id)->get();
    }

    /** Batches the viewer holds an active enrolment in — the leaderboard's own batch picker. */
    public function batches(): Collection
    {
        $viewer = auth()->user();

        if (! $viewer) {
            return collect();
        }

        $batchIds = Enrolment::where('user_id', $viewer->id)->where('status', 'active')->pluck('batch_id');

        return Batch::whereIn('id', $batchIds)->get();
    }

    public function leaderboardEnabled(): bool
    {
        $tenant = app(CurrentTenant::class);

        return $tenant->isBound() && $tenant->get()->gamification_leaderboard_enabled;
    }

    public function leaderboard(): Collection
    {
        if (! $this->leaderboardEnabled() || $this->batchId === null) {
            return collect();
        }

        $batch = $this->batches()->firstWhere('id', $this->batchId);

        return $batch ? app(LeaderboardService::class)->rankingsFor($batch) : collect();
    }

    public function render(): View
    {
        return view('analytics::gamification-widget', [
            'profile' => $this->profile(),
            'badges' => $this->badges(),
            'batches' => $this->batches(),
            'leaderboardEnabled' => $this->leaderboardEnabled(),
            'leaderboard' => $this->leaderboard(),
        ]);
    }
}
