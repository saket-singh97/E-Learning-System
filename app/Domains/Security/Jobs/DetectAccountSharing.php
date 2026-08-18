<?php

namespace App\Domains\Security\Jobs;

use App\Domains\Security\Models\AccountSharingFlag;
use App\Domains\Security\Models\DeviceSession;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * S12 B4 (FEATURES.md B12) — design doc §4 decision 4: a daily sweep, not
 * computed lazily on read, same "state transition that depends on
 * elapsed time/pattern, not a single event" reasoning every other
 * time-windowed check in this app already follows
 * (`SuspendOverdueTenantSubscriptions`, `FlagOverdueInvoices`).
 *
 * Counts distinct `device_sessions.device_fingerprint` values *first-seen*
 * (`created_at`) within a rolling 7-day window — deliberately not
 * "currently active, non-revoked" devices. B1's own `device_limit` already
 * caps concurrent devices at 2; the sharing signal this job looks for is
 * *cycling* through many different devices over the window (revoking an
 * old one via "My devices" to free a slot, then logging in from a new
 * one, repeatedly) — a pattern only `created_at` within the window, not
 * current `revoked_at` state, can surface.
 *
 * No raw-SQL query helper (`CLAUDE.md`'s own security baseline forbids
 * the `DB` facade's raw-expression escape hatches inside `app/Domains/`)
 * — distinct-counting happens in PHP via `pluck()->unique()->count()`,
 * not `COUNT(DISTINCT ...)` SQL. A daily sweep over a 7-day window is
 * not a hot path; the handful of rows per user this pulls is cheap.
 */
class DetectAccountSharing implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        app(CurrentTenant::class)->clear();

        $threshold = config('security.account_sharing_device_threshold');
        $windowStart = now()->subDays(7);

        $userIds = DeviceSession::query()
            ->where('created_at', '>=', $windowStart)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->unique();

        foreach ($userIds as $userId) {
            $this->evaluateUser((int) $userId, $windowStart, $threshold);
        }
    }

    protected function evaluateUser(int $userId, Carbon $windowStart, int $threshold): void
    {
        $rows = DeviceSession::query()
            ->where('user_id', $userId)
            ->where('created_at', '>=', $windowStart)
            ->get(['tenant_id', 'device_fingerprint']);

        $distinctCount = $rows->pluck('device_fingerprint')->unique()->count();

        if ($distinctCount < $threshold) {
            return;
        }

        // One pending flag at a time per user — a second sweep run while
        // the first flag is still unreviewed/un-enforced doesn't pile on
        // a duplicate. A fresh flag can land again once the existing one
        // has been consumed (`EnsureAccountNotFlaggedForSharing` enforces
        // it) or reviewed.
        $alreadyFlagged = AccountSharingFlag::withoutGlobalScope('tenant')
            ->where('user_id', $userId)
            ->whereNull('reviewed_at')
            ->exists();

        if ($alreadyFlagged) {
            return;
        }

        AccountSharingFlag::withoutEvents(fn () => AccountSharingFlag::create([
            'tenant_id' => $rows->first()?->tenant_id,
            'user_id' => $userId,
            'distinct_device_count_7d' => $distinctCount,
            'flagged_at' => now(),
        ]));
    }
}
