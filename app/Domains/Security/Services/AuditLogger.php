<?php

namespace App\Domains\Security\Services;

use App\Domains\Security\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The one place a sensitive action gets written to the general
 * audit_log_entries sink — design doc §4 decision 5: written
 * synchronously, not queued, so the record survives even if a queue
 * worker later fails or a job gets lost. A plain service call, not an
 * event listener with retry semantics, for the identical reason.
 *
 * $ipAddress is an explicit param, not read from the `request()` helper
 * inside this class — matches `DeviceRegistrar`'s own "explicit, never
 * ambient" discipline (S12 B1), and keeps this callable cleanly from a
 * scheduled job (`SuspendOverdueTenantSubscriptions`) with no request in
 * scope at all, not just from HTTP call sites.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $action,
        ?Model $subject = null,
        ?User $actor = null,
        ?int $tenantId = null,
        array $metadata = [],
        ?string $ipAddress = null,
    ): AuditLogEntry {
        return AuditLogEntry::create([
            'tenant_id' => $tenantId,
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject !== null ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata,
            'ip_address' => $ipAddress,
        ]);
    }
}
