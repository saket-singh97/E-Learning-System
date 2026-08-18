<?php

namespace App\Domains\Security\Jobs;

use App\Domains\Security\Models\GdprDeletionRequest;
use App\Domains\Security\Services\GdprDeletionService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Design doc §4 decision 8 — the daily sweep that actually executes a
 * deletion once its own `scheduled_for` grace window has passed, same
 * unscoped-sweep shape every other time-windowed check in this app
 * already uses (`SuspendOverdueTenantSubscriptions`, `DetectAccountSharing`).
 * `GdprDeletionRequest::request()` only ever schedules; this job is the
 * only place `GdprDeletionService::execute()` is actually called.
 *
 * One request's own failure (`execute()` already catches and marks it
 * `failed`, then rethrows) doesn't stop the sweep from reaching the rest
 * — caught per-row here so a single bad row can't block every other
 * tenant's own due deletions in the same run.
 */
class ProcessGdprDeletions implements ShouldQueue
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

        GdprDeletionRequest::withoutGlobalScope('tenant')
            ->where('status', GdprDeletionRequest::STATUS_PENDING)
            ->where('scheduled_for', '<=', now())
            ->get()
            ->each(function (GdprDeletionRequest $request): void {
                try {
                    app(GdprDeletionService::class)->execute($request);
                } catch (Throwable) {
                    // already marked failed by execute() itself; swallowed
                    // here only to let the sweep continue to the next row.
                }
            });
    }
}
