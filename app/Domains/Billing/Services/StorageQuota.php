<?php

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Models\TenantStorageUsage;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * A running counter, not a daily log — TenantStorageUsage has one row per
 * tenant (design doc §2.4 decision 5). No pre-existing storage measurement
 * anywhere in this app to build on the way BandwidthQuota had
 * BandwidthUsageDaily; every write path that stores or deletes a billable
 * file calls adjust() itself (B4's enumerated call sites).
 */
class StorageQuota
{
    public function __construct(
        private readonly TenantPlanLimits $limits,
    ) {}

    public function capFor(Tenant $tenant): int
    {
        return $this->limits->storageLimitBytes($tenant);
    }

    /**
     * Explicit tenant_id filter (withoutGlobalScope), not a bare query
     * relying on CurrentTenant already matching $tenant — same reasoning
     * BandwidthQuota::usedToday() already documents, needed here for the
     * identical reason (callers pass an arbitrary $tenant, not necessarily
     * whichever tenant happens to be ambient). Floored at 0 on read, not
     * write — see adjust()'s own note on why a transient negative value
     * can reach the row but must never be visible from here.
     */
    public function usedNow(Tenant $tenant): int
    {
        $bytes = (int) (TenantStorageUsage::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->value('bytes_used') ?? 0);

        return max(0, $bytes);
    }

    public function remainingFor(Tenant $tenant): int
    {
        return max(0, $this->capFor($tenant) - $this->usedNow($tenant));
    }

    /**
     * S11 B16 review pass — found by re-reading this class, not by a
     * failing test: the original version read `bytes_used`, computed the
     * new total in PHP, then wrote it back — a classic lost-update race.
     * Two concurrent uploads for the same tenant (two students submitting
     * assignments in the same institute at the same moment, a genuinely
     * realistic scenario unlike this block's other, narrower admin-UI
     * races) could both read the same starting value, and the second
     * write would silently overwrite the first's increment — undercounting
     * real usage against the quota this whole class exists to enforce.
     *
     * Fixed with Eloquent's increment()/decrement(), which compile to a
     * single atomic `SET bytes_used = bytes_used +/- ?` statement with no
     * PHP-side read-modify-write step to race on. Floor-at-zero moved to
     * usedNow() instead of enforced here — an atomic decrement has no
     * "but not below zero" variant, and a transient negative value on the
     * row itself is harmless (self-corrects on the next real increment,
     * and is never visible, since every read clamps it).
     *
     * Positive $deltaBytes records new usage, negative releases it.
     * Creates the row on first use (withoutEvents + explicit tenant_id,
     * same shape RecordVideoCoverageAttendance/CertificateService already
     * use). tenant_id is a real unique constraint on this table (one row
     * per tenant) — two concurrent *first-ever* adjust() calls for the
     * same tenant could both find no row and both attempt to create one;
     * the loser's insert throws a unique-constraint QueryException,
     * caught and retried once (same "catch, recompute, recover cleanly"
     * shape AssignmentSubmissionService::submitWithRetry() already
     * established in S10 B15) — the retry finds the winner's row and
     * applies this call's own delta to it via the atomic path instead.
     */
    public function adjust(Tenant $tenant, int $deltaBytes): void
    {
        $this->adjustWithRetry($tenant, $deltaBytes, allowRetry: true);
    }

    protected function adjustWithRetry(Tenant $tenant, int $deltaBytes, bool $allowRetry): void
    {
        $row = TenantStorageUsage::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->first();

        if ($row !== null) {
            if ($deltaBytes >= 0) {
                $row->increment('bytes_used', $deltaBytes);
            } else {
                $row->decrement('bytes_used', abs($deltaBytes));
            }

            return;
        }

        try {
            // Own transaction (a real SAVEPOINT when nested inside a
            // caller's own transaction, e.g. a test's RefreshDatabase
            // wrapper) — without it, the expected unique-constraint
            // QueryException poisons the whole enclosing transaction
            // (Postgres SQLSTATE 25P02), turning "recovered from the
            // race" into "crashed on the recovery attempt instead" (the
            // identical class of mistake S10 B11/B15 already document).
            DB::transaction(fn () => TenantStorageUsage::withoutEvents(fn () => TenantStorageUsage::create([
                'tenant_id' => $tenant->id,
                'bytes_used' => max(0, $deltaBytes),
            ])));
        } catch (QueryException $e) {
            if (! $allowRetry) {
                throw $e;
            }

            $this->adjustWithRetry($tenant, $deltaBytes, allowRetry: false);
        }
    }
}
