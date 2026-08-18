<?php

use App\Domains\Security\Jobs\GenerateGdprExport;
use App\Domains\Security\Models\GdprExportRequest;
use App\Domains\Tenancy\Models\Tenant;
use App\Filament\Admin\Pages\GdprExports;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

it('queues an export request for the selected user via the request action', function () {
    Queue::fake();
    $this->actingAs($this->admin);

    Livewire::test(GdprExports::class)
        ->callAction('request', data: ['user_id' => $this->subject->id]);

    $request = GdprExportRequest::sole();
    expect($request->user_id)->toBe($this->subject->id)
        ->and($request->requested_by_id)->toBe($this->admin->id)
        ->and($request->status)->toBe(GdprExportRequest::STATUS_PENDING);

    Queue::assertPushed(GenerateGdprExport::class);
});

it('lists only this tenants own export requests', function () {
    $mine = GdprExportRequest::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->subject->id,
        'requested_by_id' => $this->admin->id,
        'status' => GdprExportRequest::STATUS_PENDING,
    ]);

    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    app(CurrentTenant::class)->set($otherTenant);
    $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $notMine = GdprExportRequest::create([
        'tenant_id' => $otherTenant->id,
        'user_id' => $otherUser->id,
        'requested_by_id' => $otherUser->id,
        'status' => GdprExportRequest::STATUS_PENDING,
    ]);
    app(CurrentTenant::class)->set($this->tenant);

    $this->actingAs($this->admin);

    Livewire::test(GdprExports::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$notMine]);
});

it('shows the download action only once a request is ready and unexpired', function () {
    $notReady = GdprExportRequest::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->subject->id,
        'requested_by_id' => $this->admin->id,
        'status' => GdprExportRequest::STATUS_PROCESSING,
    ]);
    $expired = GdprExportRequest::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->subject->id,
        'requested_by_id' => $this->admin->id,
        'status' => GdprExportRequest::STATUS_READY,
        'export_disk_path' => 'gdpr-exports/x.zip',
        'expires_at' => now()->subDay(),
    ]);
    $ready = GdprExportRequest::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->subject->id,
        'requested_by_id' => $this->admin->id,
        'status' => GdprExportRequest::STATUS_READY,
        'export_disk_path' => 'gdpr-exports/y.zip',
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($this->admin);

    $test = Livewire::test(GdprExports::class);
    $test->assertTableActionHidden('download', $notReady)
        ->assertTableActionHidden('download', $expired)
        ->assertTableActionVisible('download', $ready);
});

it('is visible on the real admin panel page', function () {
    $this->actingAs($this->admin)
        ->get('http://physics.lms.test/admin/gdpr-exports')
        ->assertOk();
});
