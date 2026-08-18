<?php

use App\Domains\Security\Models\GdprDeletionRequest;
use App\Domains\Tenancy\Models\Tenant;
use App\Filament\Admin\Pages\GdprDeletions;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin->assignRole('institute_admin');
    $this->subject = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

it('schedules a pending deletion for the selected user via the request action', function () {
    $this->actingAs($this->admin);

    Livewire::test(GdprDeletions::class)
        ->callAction('request', data: ['user_id' => $this->subject->id]);

    $request = GdprDeletionRequest::sole();
    expect($request->user_id)->toBe($this->subject->id)
        ->and($request->requested_by_id)->toBe($this->admin->id)
        ->and($request->status)->toBe(GdprDeletionRequest::STATUS_PENDING);
});

it('lists only this tenants own deletion requests', function () {
    $mine = GdprDeletionRequest::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->subject->id,
        'requested_by_id' => $this->admin->id,
        'status' => GdprDeletionRequest::STATUS_PENDING,
        'scheduled_for' => now()->addDays(30),
    ]);

    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    app(CurrentTenant::class)->set($otherTenant);
    $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $notMine = GdprDeletionRequest::create([
        'tenant_id' => $otherTenant->id,
        'user_id' => $otherUser->id,
        'requested_by_id' => $otherUser->id,
        'status' => GdprDeletionRequest::STATUS_PENDING,
        'scheduled_for' => now()->addDays(30),
    ]);
    app(CurrentTenant::class)->set($this->tenant);

    $this->actingAs($this->admin);

    Livewire::test(GdprDeletions::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$notMine]);
});

it('cancels a pending request via the cancel row action, removing it', function () {
    $request = GdprDeletionRequest::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->subject->id,
        'requested_by_id' => $this->admin->id,
        'status' => GdprDeletionRequest::STATUS_PENDING,
        'scheduled_for' => now()->addDays(30),
    ]);

    $this->actingAs($this->admin);

    Livewire::test(GdprDeletions::class)
        ->callTableAction('cancel', $request);

    expect(GdprDeletionRequest::find($request->id))->toBeNull();
});

it('hides the cancel action once a request is no longer pending', function () {
    $request = GdprDeletionRequest::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->subject->id,
        'requested_by_id' => $this->admin->id,
        'status' => GdprDeletionRequest::STATUS_COMPLETED,
        'scheduled_for' => now()->subDays(1),
        'completed_at' => now(),
    ]);

    $this->actingAs($this->admin);

    Livewire::test(GdprDeletions::class)
        ->assertTableActionHidden('cancel', $request);
});

it('is visible on the real admin panel page', function () {
    $this->actingAs($this->admin)
        ->get('http://physics.lms.test/admin/gdpr-deletions')
        ->assertOk();
});
