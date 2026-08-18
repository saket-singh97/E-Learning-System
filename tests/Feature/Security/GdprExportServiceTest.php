<?php

use App\Domains\Security\Jobs\GenerateGdprExport;
use App\Domains\Security\Models\GdprExportRequest;
use App\Domains\Security\Services\GdprExportService;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('creates a pending request row and dispatches the export job', function () {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    $subject = User::factory()->create(['tenant_id' => $tenant->id]);
    $staff = User::factory()->create(['tenant_id' => $tenant->id]);

    $request = app(GdprExportService::class)->request($subject, $staff);

    expect($request)->toBeInstanceOf(GdprExportRequest::class)
        ->and($request->tenant_id)->toBe($tenant->id)
        ->and($request->user_id)->toBe($subject->id)
        ->and($request->requested_by_id)->toBe($staff->id)
        ->and($request->status)->toBe(GdprExportRequest::STATUS_PENDING);

    Queue::assertPushed(GenerateGdprExport::class, fn (GenerateGdprExport $job): bool => $job->gdprExportRequestId === $request->id
        && $job->tenant_id === $tenant->id);
});
