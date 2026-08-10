<?php

use App\Domains\AI\Jobs\TranscribeLesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\MissingTenantContextException;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pipeline\Pipeline;
use Tests\Fixtures\Jobs\NoopTenantJob;

uses(RefreshDatabase::class);

/**
 * ShouldQueue job middleware only runs through Illuminate\Queue\CallQueuedHandler,
 * which a plain dispatch()/dispatchSync() call never invokes. Running the job
 * through the same Pipeline mechanism CallQueuedHandler uses is the standard
 * way to exercise job middleware in a test without a real queue worker.
 */
function runThroughJobMiddleware(NoopTenantJob $job): void
{
    // RebindsTenant::handle() returns void and calls $next($job) as a bare
    // statement (matching how the real CallQueuedHandler ignores middleware
    // return values) — so this pipeline is run for its side effects on $job,
    // never for a return value.
    app(Pipeline::class)
        ->send($job)
        ->through($job->middleware())
        ->then(fn (NoopTenantJob $job) => $job->handle());
}

it('binds CurrentTenant and runs the job when tenant_id is present', function () {
    $tenant = Tenant::factory()->create();

    $job = new NoopTenantJob(tenant_id: $tenant->id);

    runThroughJobMiddleware($job);

    expect($job->ran)->toBeTrue()
        ->and(app(CurrentTenant::class)->id())->toBe($tenant->id);
});

it('fails the job loudly with MissingTenantContextException when tenant_id is missing', function () {
    $job = (new NoopTenantJob(tenant_id: null))->withFakeQueueInteractions();

    runThroughJobMiddleware($job);

    $job->assertFailedWith(MissingTenantContextException::class);
    expect($job->ran)->toBeFalse();
});

/**
 * Closes the 2026-08-09 gap for real: RebindsTenant against S3's actual
 * first ShouldQueue job in app/, not just the NoopTenantJob fixture. Only
 * the null-tenant path is exercised here — the middleware fails the job
 * before $next() runs, so handle() (and its AiProvider dependency) is never
 * reached. The bound-tenant path is proven by IngestionPipelineTest, which
 * runs TranscribeLesson for real end-to-end.
 */
it('fails TranscribeLesson, a real domain job, loudly without tenant_id', function () {
    $job = (new TranscribeLesson(lessonArtifactId: 1, tenant_id: null))->withFakeQueueInteractions();

    app(Pipeline::class)
        ->send($job)
        ->through($job->middleware())
        ->then(fn () => null);

    $job->assertFailedWith(MissingTenantContextException::class);
});
