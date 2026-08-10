<?php

namespace Tests\Fixtures\Jobs;

use App\Support\Tenancy\RebindsTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * No real ShouldQueue job exists in app/ yet, so RebindsTenant has never
 * actually run (S1 verification-pass finding, 2026-08-09). This fixture
 * exists only to exercise the job middleware, same throwaway-fixture pattern
 * already used repeatedly in this codebase for infra with no natural caller.
 */
class NoopTenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $ran = false;

    public function __construct(public ?int $tenant_id) {}

    public function middleware(): array
    {
        return [new RebindsTenant];
    }

    public function handle(): void
    {
        $this->ran = true;
    }
}
