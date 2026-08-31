<?php

namespace App\Livewire;

use App\Domains\Assessments\Models\RankCard;
use App\Domains\Assessments\Models\TestSeries;
use App\Domains\Assessments\Services\TestSeriesRankingService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * S16 B13 (I10 pt.2) — no existing student-facing page to embed this on
 * (same "add a small host route" shape B3's GeofenceCheckIn already
 * took). Shows the viewer's own rank/percentile/gap-to-topper only, not
 * the full roster — a student sees their own standing, not classmates'
 * names, matching this app's existing "no cross-student data exposure"
 * posture (plan's own B13 note).
 */
class TestSeriesResults extends Component
{
    public TestSeries $series;

    public function mount(TestSeries $series): void
    {
        $this->series = $series;
    }

    public function render(): View
    {
        $viewer = auth()->user();

        $ownRow = null;
        $topScore = null;
        $totalParticipants = 0;
        $rankCard = null;

        if ($viewer !== null) {
            $rankings = app(TestSeriesRankingService::class)->rankingsFor($this->series, $viewer);
            $totalParticipants = $rankings->count();
            $topScore = $rankings->first()['total_score'] ?? null;
            $ownRow = $rankings->firstWhere('user_id', $viewer->id);

            if ($ownRow !== null) {
                $rankCard = RankCard::where('test_series_id', $this->series->id)
                    ->where('user_id', $viewer->id)
                    ->first();
            }
        }

        return view('livewire.test-series-results', [
            'ownRow' => $ownRow,
            'topScore' => $topScore,
            'totalParticipants' => $totalParticipants,
            'rankCard' => $rankCard,
        ]);
    }
}
