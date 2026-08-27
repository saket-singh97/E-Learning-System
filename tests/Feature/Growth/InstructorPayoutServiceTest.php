<?php

use App\Domains\Growth\Models\InstructorPayout;
use App\Domains\Growth\Services\InstructorPayoutService;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\InstructorPayoutActionException;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->service = app(InstructorPayoutService::class);
});

it('creates a salaried payout for exactly its configured amount, no computation', function () {
    $payout = $this->service->createSalaried(
        $this->instructor,
        now()->startOfMonth(),
        now()->endOfMonth(),
        7500,
    );

    expect((float) $payout->amount)->toBe(7500.0)
        ->and($payout->basis)->toBe('salaried')
        ->and($payout->status)->toBe('pending')
        ->and($payout->instructor_id)->toBe($this->instructor->id);
});

it('walks pending through approved to paid, one gated step at a time', function () {
    $payout = $this->service->createSalaried($this->instructor, now()->startOfMonth(), now()->endOfMonth(), 5000);

    $approved = $this->service->approve($payout, $this->admin);
    expect($approved->status)->toBe('approved')
        ->and($approved->approved_by)->toBe($this->admin->id)
        ->and($approved->approved_at)->not->toBeNull()
        ->and($approved->paid_at)->toBeNull();

    $paid = $this->service->markPaid($approved);
    expect($paid->status)->toBe('paid')
        ->and($paid->paid_at)->not->toBeNull();
});

it('refuses to approve a payout that is not pending', function () {
    $payout = $this->service->createSalaried($this->instructor, now()->startOfMonth(), now()->endOfMonth(), 5000);
    $this->service->approve($payout, $this->admin);

    expect(fn () => $this->service->approve($payout->fresh(), $this->admin))
        ->toThrow(InstructorPayoutActionException::class);
});

it('refuses to mark a still-pending payout paid, skipping the approval step', function () {
    $payout = $this->service->createSalaried($this->instructor, now()->startOfMonth(), now()->endOfMonth(), 5000);

    expect(fn () => $this->service->markPaid($payout))
        ->toThrow(InstructorPayoutActionException::class);
    expect($payout->fresh()->status)->toBe('pending');
});

it('refuses to reject a payout that has already been approved', function () {
    $payout = $this->service->createSalaried($this->instructor, now()->startOfMonth(), now()->endOfMonth(), 5000);
    $this->service->approve($payout, $this->admin);

    expect(fn () => $this->service->reject($payout->fresh()))
        ->toThrow(InstructorPayoutActionException::class);
});

it('rejects a pending payout, leaving it out of the approved/paid path', function () {
    $payout = $this->service->createSalaried($this->instructor, now()->startOfMonth(), now()->endOfMonth(), 5000);

    $rejected = $this->service->reject($payout);

    expect($rejected->status)->toBe('rejected');
});
