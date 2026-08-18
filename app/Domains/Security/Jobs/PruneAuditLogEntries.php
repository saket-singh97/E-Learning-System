<?php

namespace App\Domains\Security\Jobs;

use App\Domains\Security\Models\AuditLogEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Design doc §4 decision 6 — Postgres has no native row-TTL, so retention
 * is a scheduled sweep, not a database-level expiry. Monthly, not daily
 * like the rest of this app's own sweeps (`SuspendOverdueTenantSubscriptions`,
 * `DetectAccountSharing`) — a 365-day cutoff shifts by at most a day either
 * way regardless of sweep cadence, so a monthly cadence is enough and cuts
 * the job's own overhead 30x versus running it daily for no benefit.
 *
 * Hard delete, not soft — an entry aged past its own retention window has
 * nothing left to "restore" (decision 6, explicit). `AuditLogEntry` is not
 * `BelongsToTenant` (B0), so no `CurrentTenant` clearing is needed the way
 * every other unscoped sweep in this app requires — there is no tenant
 * scope on this model to begin with.
 */
class PruneAuditLogEntries implements ShouldQueue
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
        $cutoff = now()->subDays((int) config('security.audit_log_retention_days'));

        AuditLogEntry::where('created_at', '<', $cutoff)->delete();
    }
}
