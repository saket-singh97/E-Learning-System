<?php

use App\Domains\Security\Models\AuditLogEntry;
use App\Domains\Tenancy\Models\Tenant;
use App\Filament\Pages\AuditLog;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->superAdmin = User::factory()->create(['tenant_id' => null]);
    $this->superAdmin->assignRole('super_admin');
});

it('lists audit log entries across every tenant, and platform-level ones', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);

    $tenantScoped = AuditLogEntry::create([
        'tenant_id' => $tenant->id,
        'action' => 'role.assigned',
    ]);
    $platformLevel = AuditLogEntry::create([
        'action' => 'tenant.suspended',
    ]);

    $this->actingAs($this->superAdmin);
    $this->get('http://lms.test/super/audit-log')->assertOk();

    Livewire::test(AuditLog::class)
        ->assertCanSeeTableRecords([$tenantScoped, $platformLevel]);
});

it('filters entries by action', function () {
    $matching = AuditLogEntry::create(['action' => 'plan.updated']);
    $other = AuditLogEntry::create(['action' => 'impersonation.started']);

    $this->actingAs($this->superAdmin);

    Livewire::test(AuditLog::class)
        ->filterTable('action', 'plan.updated')
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other]);
});

it('filters entries by tenant', function () {
    $tenantA = Tenant::factory()->create(['subdomain' => 'physics']);
    $tenantB = Tenant::factory()->create(['subdomain' => 'chemistry']);

    $entryA = AuditLogEntry::create(['tenant_id' => $tenantA->id, 'action' => 'role.assigned']);
    $entryB = AuditLogEntry::create(['tenant_id' => $tenantB->id, 'action' => 'role.assigned']);

    $this->actingAs($this->superAdmin);

    Livewire::test(AuditLog::class)
        ->filterTable('tenant_id', $tenantA->id)
        ->assertCanSeeTableRecords([$entryA])
        ->assertCanNotSeeTableRecords([$entryB]);
});

it('is refused to a non-super_admin', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');

    $this->actingAs($admin);
    $this->get('http://lms.test/super/audit-log')->assertForbidden();
});
