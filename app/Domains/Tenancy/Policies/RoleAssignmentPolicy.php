<?php

namespace App\Domains\Tenancy\Policies;

use App\Domains\Tenancy\Models\RoleAssignmentAudit;
use App\Domains\Tenancy\Services\RoleHierarchy;
use App\Exceptions\PrivilegeEscalationException;
use App\Models\User;

class RoleAssignmentPolicy
{
    /**
     * Assign $role to $target on $actor's behalf. Writes an audit row for
     * every attempt, allowed or not, then throws when the actor's effective
     * role cannot assign $role — at or above the actor's own level, or the
     * actor's role has no assignment rights at all (instructor and below).
     *
     * User deliberately has no BelongsToTenant scope (roles are global to a
     * user, not per-tenant — see the S1 design doc), so nothing at the query
     * layer stops an institute-scoped actor and a target from different
     * tenants reaching this method together. Downward-only role hierarchy
     * alone doesn't catch that: an institute_admin in tenant A can legally
     * assign 'student' to *any* user by hierarchy rules, tenant B included.
     * Block it here explicitly. A platform-level actor (tenant_id null,
     * e.g. a super admin with no institute) is exempt — cross-tenant
     * assignment is exactly their job.
     */
    public function assign(User $actor, User $target, string $role): void
    {
        $actorRole = RoleHierarchy::highestRole($actor->getRoleNames()->all()) ?? '';
        $sameTenant = $actor->tenant_id === null || $target->tenant_id === null || $actor->tenant_id === $target->tenant_id;
        $allowed = $sameTenant && RoleHierarchy::canAssign($actorRole, $role);

        RoleAssignmentAudit::create([
            'actor_id' => $actor->id,
            'target_user_id' => $target->id,
            'actor_role' => $actorRole,
            'attempted_role' => $role,
            'allowed' => $allowed,
        ]);

        if (! $allowed) {
            throw PrivilegeEscalationException::forAttempt($actorRole, $role);
        }

        $target->syncRoles([$role]);
    }
}
