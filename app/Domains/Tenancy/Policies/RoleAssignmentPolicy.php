<?php

namespace App\Domains\Tenancy\Policies;

use App\Domains\Billing\Services\SeatQuota;
use App\Domains\Tenancy\Models\RoleAssignmentAudit;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\RoleHierarchy;
use App\Exceptions\PrivilegeEscalationException;
use App\Exceptions\SeatLimitExceededException;
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

        $this->guardSeatLimit($target, $role);

        $target->syncRoles([$role]);
    }

    /**
     * S11 B3 (Q2) — assign() is the single chokepoint every real role grant
     * already passes through (parent invites, and this policy's own future
     * callers once an admin/instructor-invite UI lands), so it's the
     * correct point-of-action for seat enforcement even though nothing in
     * this codebase yet calls it with 'institute_admin'/'instructor'
     * outside tests — enforcing here means it's automatically wired up the
     * moment such a UI exists, no separate hookup needed later.
     *
     * Calling Billing\Services\SeatQuota from a Tenancy\Policies class is a
     * deliberate, precedented exception to "domains talk through Events
     * only" — Policies are this codebase's existing sanctioned cross-domain
     * authorization gate (Comms\Services\ParentInviteCodeService and
     * Comms\Filament\...\CreateParentGuardian already call straight into
     * this exact class from a different domain); a synchronous "block this
     * action" check cannot be expressed as an event anyway, since events
     * react after the fact rather than gating before it.
     */
    private function guardSeatLimit(User $target, string $role): void
    {
        if (! in_array($role, SeatQuota::SEAT_ROLES, true)) {
            return;
        }

        if ($target->hasAnyRole(SeatQuota::SEAT_ROLES)) {
            return; // already occupies a seat — a tier change, not new headcount
        }

        if ($target->tenant_id === null) {
            return; // platform-level user, not a real institute seat
        }

        $tenant = Tenant::find($target->tenant_id);

        if ($tenant !== null && app(SeatQuota::class)->remainingFor($tenant) === 0) {
            throw SeatLimitExceededException::forTenant($tenant->id);
        }
    }
}
