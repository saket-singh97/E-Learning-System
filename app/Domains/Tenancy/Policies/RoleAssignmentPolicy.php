<?php

namespace App\Domains\Tenancy\Policies;

use App\Domains\Billing\Services\SeatQuota;
use App\Domains\Security\Services\AuditLogger;
use App\Domains\Tenancy\Models\RoleAssignmentAudit;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\RoleHierarchy;
use App\Exceptions\PrivilegeEscalationException;
use App\Exceptions\SeatLimitExceededException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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

        if (! $this->addsNewSeat($target, $role)) {
            $target->syncRoles([$role]);
        } else {
            // S11 B15 — two concurrent assign() calls promoting two
            // *different* targets to a seat-consuming role on the same
            // tenant could otherwise both pass remainingFor() === 0 before
            // either commits, over-provisioning by one seat (no unique DB
            // constraint exists to catch this the way S10 B15's version-
            // number race had). Locking the Tenant row (same technique
            // PlanChangeService's own B15 fix uses) serializes only this
            // seat-consuming path — every other role assignment (student/
            // parent/a tier change) is untouched by the lock, since only
            // this branch needs it.
            DB::transaction(function () use ($target, $role): void {
                $tenant = Tenant::where('id', $target->tenant_id)->lockForUpdate()->first();

                if ($tenant !== null && app(SeatQuota::class)->remainingFor($tenant) === 0) {
                    throw SeatLimitExceededException::forTenant($tenant->id);
                }

                $target->syncRoles([$role]);
            });
        }

        // Written only once the role has actually taken effect (S12 B14's
        // own review pass) — this used to fire before the seat-quota check
        // above, so a SeatLimitExceededException left a 'role.assigned'
        // audit row behind for an assignment that never happened.
        app(AuditLogger::class)->record(
            action: 'role.assigned',
            subject: $target,
            actor: $actor,
            tenantId: $target->tenant_id ?? $actor->tenant_id,
            metadata: ['role' => $role, 'actor_role' => $actorRole],
        );
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
    private function addsNewSeat(User $target, string $role): bool
    {
        if (! in_array($role, SeatQuota::SEAT_ROLES, true)) {
            return false;
        }

        if ($target->hasAnyRole(SeatQuota::SEAT_ROLES)) {
            return false; // already occupies a seat — a tier change, not new headcount
        }

        return $target->tenant_id !== null; // false = platform-level user, not a real institute seat
    }
}
