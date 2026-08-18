<?php

use App\Domains\Security\Jobs\ProcessGdprDeletions;
use App\Domains\Security\Models\GdprDeletionRequest;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function gdprDeletionFixture(string $status, ?string $scheduledFor): array
{
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    $subject = User::factory()->create(['tenant_id' => $tenant->id]);
    $staff = User::factory()->create(['tenant_id' => $tenant->id]);

    $request = GdprDeletionRequest::create([
        'user_id' => $subject->id,
        'requested_by_id' => $staff->id,
        'status' => $status,
        'scheduled_for' => $scheduledFor,
    ]);

    app(CurrentTenant::class)->clear();

    return [$subject, $request];
}

it('executes a pending request whose grace window has passed', function () {
    [$subject, $request] = gdprDeletionFixture(GdprDeletionRequest::STATUS_PENDING, now()->subDay()->toDateTimeString());

    (new ProcessGdprDeletions)->handle();

    expect($request->fresh()->status)->toBe(GdprDeletionRequest::STATUS_COMPLETED);
    expect($subject->fresh()->name)->toBe('Deleted User');
});

it('leaves a pending request alone while still inside its grace window', function () {
    [, $request] = gdprDeletionFixture(GdprDeletionRequest::STATUS_PENDING, now()->addDays(5)->toDateTimeString());

    (new ProcessGdprDeletions)->handle();

    expect($request->fresh()->status)->toBe(GdprDeletionRequest::STATUS_PENDING);
});

it('never touches a request that is not pending, even if its schedule has passed', function () {
    [, $request] = gdprDeletionFixture(GdprDeletionRequest::STATUS_COMPLETED, now()->subDay()->toDateTimeString());

    (new ProcessGdprDeletions)->handle();

    expect($request->fresh()->status)->toBe(GdprDeletionRequest::STATUS_COMPLETED);
});

it('processes every due tenants own request in one unscoped pass', function () {
    [$subjectA, $requestA] = gdprDeletionFixture(GdprDeletionRequest::STATUS_PENDING, now()->subHours(2)->toDateTimeString());
    [$subjectB, $requestB] = gdprDeletionFixture(GdprDeletionRequest::STATUS_PENDING, now()->subDay()->toDateTimeString());

    $unrelated = Tenant::factory()->create();
    app(CurrentTenant::class)->set($unrelated);

    (new ProcessGdprDeletions)->handle();

    expect($requestA->fresh()->status)->toBe(GdprDeletionRequest::STATUS_COMPLETED)
        ->and($requestB->fresh()->status)->toBe(GdprDeletionRequest::STATUS_COMPLETED);
});

it('runs cleanly with no due requests at all', function () {
    expect(fn () => (new ProcessGdprDeletions)->handle())->not->toThrow(Throwable::class);
});
