<?php

namespace App\Filament\Admin\Widgets;

use App\Domains\Assignments\Services\GradebookService;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Comms\Services\StudentMetrics;
use App\Domains\Fees\Models\FeeInvoice;
use App\Models\User;
use App\Support\Caching\AggregateCache;
use App\Support\Tenancy\CurrentTenant;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;

/**
 * Auto-discovered by AdminPanelProvider's discoverWidgets(in:
 * app_path('Filament/Admin/Widgets')) — no explicit ->widgets([...])
 * listing needed, unlike TokenSpendWidget (which lives outside that root
 * and is registered by hand).
 *
 * The at-risk threshold + check itself moved to StudentMetrics::AT_RISK_THRESHOLD
 * / isAtRisk() in S9 B13, once SendLowAttendanceAlert needed the exact
 * same definition this widget originated (S8 B5) — a service that already
 * computes attendance/coverage per student is the natural shared home for
 * "is this student at risk," not a Filament widget class.
 */
class InstituteOverviewWidget extends StatsOverviewWidget
{
    /**
     * S12 B12 (design doc §4 decision 11) — all 4 numbers cached as one
     * entry, keyed on the bound tenant (0 when unbound, matching the same
     * defensive `isBound()` check `tenantStudents()`/`gradebookAtRiskCount()`
     * already use for a direct `Livewire::test()` with no panel context).
     * `at_risk_count`/`gradebook_at_risk_count` are fully covered by this
     * block's own bust hooks (`AttendanceRecord`/`QuizAttempt`/
     * `AssignmentSubmission`); `active_enrolments`/`paid_revenue` are not
     * (enrolment approval, payment webhooks) and lean on the TTL safety
     * net for that gap, same posture `StudentMetrics::summarize()`'s own
     * cached `fee_status` already takes.
     */
    protected function getStats(): array
    {
        $tenantId = app(CurrentTenant::class)->isBound() ? app(CurrentTenant::class)->id() : 0;

        $stats = app(AggregateCache::class)->rememberInstituteOverview($tenantId, fn (): array => [
            'active_enrolments' => $this->activeEnrolmentCount(),
            'paid_revenue' => $this->paidRevenue(),
            'at_risk_count' => $this->atRiskCount(),
            'gradebook_at_risk_count' => $this->gradebookAtRiskCount(),
        ]);

        return [
            Stat::make('Active enrolments', (string) $stats['active_enrolments']),
            Stat::make('Revenue (paid invoices)', '₹'.number_format($stats['paid_revenue'], 2)),
            Stat::make('At-risk students', (string) $stats['at_risk_count'])
                ->description('Attendance or coverage below '.StudentMetrics::AT_RISK_THRESHOLD.'%'),
            // S10 J6 — a distinct axis from the attendance/coverage flag
            // above, not a replacement: a student can be at-risk on one and
            // fine on the other (design doc §2.3).
            Stat::make('At-risk on grades', (string) $stats['gradebook_at_risk_count'])
                ->description('Weighted gradebook score below '.GradebookService::GRADEBOOK_AT_RISK_THRESHOLD.'% in at least one enrolled course'),
        ];
    }

    protected function activeEnrolmentCount(): int
    {
        return Enrolment::where('status', 'active')->count();
    }

    protected function paidRevenue(): float
    {
        return (float) FeeInvoice::where('status', 'paid')->sum('total_amount');
    }

    protected function atRiskCount(): int
    {
        return $this->tenantStudents()
            ->filter(fn (User $student): bool => $this->isAtRisk($student))
            ->count();
    }

    protected function tenantStudents(): Collection
    {
        return User::role('student')
            ->when(
                app(CurrentTenant::class)->isBound(),
                fn ($query) => $query->where('tenant_id', app(CurrentTenant::class)->id()),
            )
            ->get();
    }

    protected function isAtRisk(User $student): bool
    {
        $metrics = app(StudentMetrics::class);

        return $metrics->isAtRisk($metrics->summarize($student));
    }

    /**
     * Gradebook at-risk has no student-wide meaning the way attendance
     * does — a score is always per-course (GradebookService::scoreFor()
     * takes a Course) — so this counts distinct students flagged in *at
     * least one* course they're actively enrolled in, not an average
     * across courses.
     */
    protected function gradebookAtRiskCount(): int
    {
        $gradebook = app(GradebookService::class);

        return Enrolment::where('status', 'active')
            ->when(
                app(CurrentTenant::class)->isBound(),
                fn ($query) => $query->where('tenant_id', app(CurrentTenant::class)->id()),
            )
            ->with(['batch.course', 'user'])
            ->get()
            ->filter(fn (Enrolment $enrolment): bool => $enrolment->batch?->course !== null
                && $gradebook->scoreFor($enrolment->user, $enrolment->batch->course)['is_at_risk'])
            ->pluck('user_id')
            ->unique()
            ->count();
    }
}
