<?php

namespace App\Domains\Fees\Services;

use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * S24 U23 pt.1 — the institute owner's own per-tenant counterpart to
 * `PlatformRevenueAnalytics` (Billing domain, Super-panel/cross-tenant),
 * same posture: pure query service, no schema, `withoutGlobalScope('tenant')`
 * plus an explicit `tenant_id` filter rather than trusting `CurrentTenant`
 * to already match the `$tenant` argument (same convention `SeatQuota`/
 * `StorageQuota` already establish for a service taking an explicit
 * `Tenant` param) — never a bare query relying on ambient state, and
 * plain arrays/floats, no DTO classes, matching this codebase's own
 * analytics-service return-shape convention.
 *
 * `FeeInvoice::STATUSES` is `['pending', 'paid', 'overdue', 'cancelled']`
 * — there is no `'partial'` status in this codebase (confirmed, not
 * assumed) and no billing-period/cycle column on `FeeInvoice` either, so
 * "current period" (design §4 decision 6) has nothing to filter
 * on — `collectionRate()` covers every non-cancelled invoice for the
 * tenant, not a narrowed period, an accepted v1 scope.
 */
class FeeAnalyticsService
{
    public function collectionRate(Tenant $tenant): float
    {
        $invoices = FeeInvoice::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('status', '!=', 'cancelled')
            ->get();

        $total = (float) $invoices->sum('total_amount');

        if ($total <= 0.0) {
            return 0.0;
        }

        $paid = (float) $invoices->where('status', 'paid')->sum('total_amount');

        return round($paid / $total * 100, 2);
    }

    public function projectedRevenue(Tenant $tenant): float
    {
        return (float) FeeInvoice::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['pending', 'overdue'])
            ->where('due_date', '>', now()->toDateString())
            ->sum('total_amount');
    }

    /**
     * Days-past-due buckets, computed from `due_date` directly rather than
     * trusting `FlagOverdueInvoices`' own `status = 'overdue'` flip to
     * have already run for every row — a `pending` invoice whose due date
     * has already passed is just as much a defaulter as a flagged
     * `overdue` one. No 3-bucket precedent existed anywhere in this
     * codebase to reuse (`AccountSharingFlag`'s own classification is a
     * single binary threshold, not a 0-30/31-60/60+ split, checked
     * directly rather than assumed).
     *
     * @return array{'0-30': array{count: int, amount: float}, '31-60': array{count: int, amount: float}, '60+': array{count: int, amount: float}}
     */
    public function defaulterAgeing(Tenant $tenant): array
    {
        $buckets = [
            '0-30' => ['count' => 0, 'amount' => 0.0],
            '31-60' => ['count' => 0, 'amount' => 0.0],
            '60+' => ['count' => 0, 'amount' => 0.0],
        ];

        $today = now()->startOfDay();

        FeeInvoice::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['pending', 'overdue'])
            ->where('due_date', '<', $today->toDateString())
            ->get()
            ->each(function (FeeInvoice $invoice) use (&$buckets, $today): void {
                $daysPastDue = (int) Carbon::parse($invoice->due_date)->diffInDays($today);

                $bucket = match (true) {
                    $daysPastDue <= 30 => '0-30',
                    $daysPastDue <= 60 => '31-60',
                    default => '60+',
                };

                $buckets[$bucket]['count']++;
                $buckets[$bucket]['amount'] += (float) $invoice->total_amount;
            });

        return $buckets;
    }
}
