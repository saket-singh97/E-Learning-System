<?php

use App\Domains\Security\Models\AuditLogEntry;
use App\Domains\Security\Services\AuditLogger;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records a full sensitive-action entry', function () {
    $tenant = Tenant::factory()->create();
    $actor = User::factory()->create(['tenant_id' => $tenant->id]);
    $target = User::factory()->create(['tenant_id' => $tenant->id]);

    $entry = app(AuditLogger::class)->record(
        action: 'role.assigned',
        subject: $target,
        actor: $actor,
        tenantId: $tenant->id,
        metadata: ['role' => 'instructor'],
        ipAddress: '10.0.0.1',
    );

    expect($entry)->toBeInstanceOf(AuditLogEntry::class)
        ->and($entry->tenant_id)->toBe($tenant->id)
        ->and($entry->actor_id)->toBe($actor->id)
        ->and($entry->action)->toBe('role.assigned')
        ->and($entry->subject_type)->toBe(User::class)
        ->and($entry->subject_id)->toBe($target->id)
        ->and($entry->metadata)->toBe(['role' => 'instructor'])
        ->and($entry->ip_address)->toBe('10.0.0.1');
});

it('records a platform-level, system-initiated entry with every optional field left null', function () {
    $entry = app(AuditLogger::class)->record(action: 'tenant.suspended');

    expect($entry->tenant_id)->toBeNull()
        ->and($entry->actor_id)->toBeNull()
        ->and($entry->subject_type)->toBeNull()
        ->and($entry->subject_id)->toBeNull()
        ->and($entry->metadata)->toBe([])
        ->and($entry->ip_address)->toBeNull();
});

it('survives the subject record being deleted afterwards, same as impersonation_audits does', function () {
    $tenant = Tenant::factory()->create();
    $actor = User::factory()->create(['tenant_id' => $tenant->id]);

    $entry = app(AuditLogger::class)->record(
        action: 'impersonation.started',
        subject: $actor,
        actor: $actor,
        tenantId: $tenant->id,
    );

    $actor->forceDelete();

    expect($entry->fresh()->actor_id)->toBeNull();
});
