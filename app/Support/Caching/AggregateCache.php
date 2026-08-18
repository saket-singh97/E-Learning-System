<?php

namespace App\Support\Caching;

use Illuminate\Support\Facades\Cache;

/**
 * Design doc §4 decision 11 — caches computed aggregates only
 * (`GradebookService::scoreFor()`, `StudentMetrics::summarize()`,
 * `InstituteOverviewWidget`'s own numbers), never raw model reads —
 * `StudentMetrics::summarize()`'s own `outstanding_invoices` list is
 * deliberately excluded from what gets cached for exactly that reason
 * (see that class's own docblock). Keys are tenant+entity-scoped, one
 * `remember*()`/`forget*()` pair per aggregate shape, matching the three
 * named cache targets exactly rather than one generic `remember(string
 * $key, ...)` a caller could key however it likes — a typo'd key string
 * at a call site would silently create an orphaned cache entry no
 * `forget*()` call ever reaches.
 */
class AggregateCache
{
    /**
     * @param  callable(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    public function rememberGradebookScore(int $tenantId, int $studentId, int $courseId, callable $callback): array
    {
        return Cache::remember($this->gradebookKey($tenantId, $studentId, $courseId), $this->ttl(), $callback);
    }

    public function forgetGradebookScore(int $tenantId, int $studentId, int $courseId): void
    {
        Cache::forget($this->gradebookKey($tenantId, $studentId, $courseId));
    }

    /**
     * @param  callable(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    public function rememberStudentMetrics(int $tenantId, int $studentId, callable $callback): array
    {
        return Cache::remember($this->studentMetricsKey($tenantId, $studentId), $this->ttl(), $callback);
    }

    public function forgetStudentMetrics(int $tenantId, int $studentId): void
    {
        Cache::forget($this->studentMetricsKey($tenantId, $studentId));
    }

    /**
     * @param  callable(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    public function rememberInstituteOverview(int $tenantId, callable $callback): array
    {
        return Cache::remember($this->instituteOverviewKey($tenantId), $this->ttl(), $callback);
    }

    public function forgetInstituteOverview(int $tenantId): void
    {
        Cache::forget($this->instituteOverviewKey($tenantId));
    }

    protected function ttl(): int
    {
        return (int) config('caching.aggregate_ttl_minutes') * 60;
    }

    protected function gradebookKey(int $tenantId, int $studentId, int $courseId): string
    {
        return "aggregate:{$tenantId}:gradebook:{$studentId}:{$courseId}";
    }

    protected function studentMetricsKey(int $tenantId, int $studentId): string
    {
        return "aggregate:{$tenantId}:student-metrics:{$studentId}";
    }

    protected function instituteOverviewKey(int $tenantId): string
    {
        return "aggregate:{$tenantId}:institute-overview";
    }
}
