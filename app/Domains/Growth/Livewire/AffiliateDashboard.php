<?php

namespace App\Domains\Growth\Livewire;

use App\Domains\Growth\Models\AffiliateCode;
use App\Domains\Growth\Services\AffiliatePayoutService;
use App\Exceptions\AffiliatePayoutActionException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * S20 R2 — same "no auth middleware, a guest sees a log-in prompt inside
 * the component" posture `Calendar` (Planner) already established. Scoped
 * to `owner_user_id === auth()->id()` on every read — an affiliate must
 * never see another affiliate's own codes/numbers, even within the same
 * tenant (design doc's own test requirement).
 */
class AffiliateDashboard extends Component
{
    public string $requestAmount = '';

    public ?int $requestingCodeId = null;

    /**
     * @return Collection<int, array{code: AffiliateCode, referral_count: int, confirmed: float, reversed: float, balance: float}>
     */
    public function codes(): Collection
    {
        $viewer = auth()->user();

        if ($viewer === null) {
            return collect();
        }

        return AffiliateCode::where('owner_user_id', $viewer->id)
            ->with('referrals', 'payoutRequests')
            ->get()
            ->map(function (AffiliateCode $code): array {
                $confirmed = (float) $code->referrals->where('status', 'confirmed')->sum('commission_amount');
                // Computed from the already-eager-loaded relations, not
                // AffiliatePayoutService::balanceFor() — that method issues
                // its own fresh queries via ->referrals()/->payoutRequests(),
                // ignoring the with() above and re-introducing an N+1 across
                // every code this affiliate owns. Same formula, kept in
                // sync by hand (caught in B14's own review pass).
                $claimed = (float) $code->payoutRequests->where('status', '!=', 'rejected')->sum('amount');

                return [
                    'code' => $code,
                    'referral_count' => $code->referrals->count(),
                    'confirmed' => $confirmed,
                    'reversed' => (float) $code->referrals->where('status', 'reversed')->sum('commission_amount'),
                    'balance' => round($confirmed - $claimed, 2),
                ];
            });
    }

    public function requestPayout(int $codeId): void
    {
        $viewer = auth()->user();

        if ($viewer === null) {
            return;
        }

        $code = AffiliateCode::where('owner_user_id', $viewer->id)->find($codeId);

        if ($code === null) {
            return;
        }

        $this->validate(['requestAmount' => 'required|numeric|min:0.01']);

        try {
            app(AffiliatePayoutService::class)->requestPayout($code, (float) $this->requestAmount);
            $this->requestAmount = '';
            $this->requestingCodeId = null;
        } catch (AffiliatePayoutActionException $e) {
            $this->addError('requestAmount', $e->getMessage());
        }
    }

    public function render(): View
    {
        return view('growth::affiliate-dashboard', [
            'codes' => $this->codes(),
            'canRespond' => auth()->user() !== null,
        ]);
    }
}
