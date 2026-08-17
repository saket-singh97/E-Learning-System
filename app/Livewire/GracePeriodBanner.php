<?php

namespace App\Livewire;

use App\Domains\Billing\Models\TenantSubscription;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * S11 B9 (design doc §3.3, plan B9) — Admin-panel-only: an institute_admin
 * is the only role that should ever see this, matching Q4's own "grace
 * period" being an institute-level billing concern, not a super_admin (no
 * tenant of their own) or instructor/student one. The panel restriction
 * lives in AppServiceProvider's own render-hook registration (its own
 * closure only renders `<livewire:grace-period-banner />` at all when the
 * current panel is 'admin' — a `scopes: 'admin'` registration silently
 * never fired, see that file's note), not here — this component has no
 * panel context of its own when unit-tested directly via `Livewire::test()`,
 * so gating on it here would make direct component tests fail for a
 * reason that has nothing to do with the logic they're testing.
 *
 * Reads through the ambient CurrentTenant scope on purpose, unlike
 * Billing's own quota services (SeatQuota/StorageQuota) — those
 * deliberately decouple from ambient state because they're called from
 * arbitrary write paths; this only ever renders inside a real Admin panel
 * request, where ResolveTenant has already bound CurrentTenant to exactly
 * the tenant this banner should be reporting on.
 */
class GracePeriodBanner extends Component
{
    public function render(): View
    {
        $subscription = app(CurrentTenant::class)->isBound()
            ? TenantSubscription::where('status', TenantSubscription::STATUS_GRACE)->latest('id')->first()
            : null;

        $daysLeft = null;

        if ($subscription?->grace_ends_at !== null) {
            // diffInDays() returns a float (precise sub-day fraction), not
            // an int — ceil() so "4 days left" reads as 4 for the whole
            // stretch of day 4, not just its first instant, and never
            // undercounts by rounding a few-microseconds-elapsed 3.99997
            // down to 3 (found by a failing test asserting "4 days" a
            // moment after `grace_ends_at` was set to exactly +4 days).
            $daysLeft = now()->isAfter($subscription->grace_ends_at)
                ? 0
                : (int) ceil(now()->diffInDays($subscription->grace_ends_at));
        }

        return view('livewire.grace-period-banner', [
            'daysLeft' => $daysLeft,
        ]);
    }
}
