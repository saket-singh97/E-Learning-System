<?php

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Models\ImpersonationAudit;
use App\Exceptions\CannotImpersonateException;
use App\Models\User;

/**
 * The one place impersonation sessions start and stop — same "one shared
 * path, an audit row for the sensitive action" posture RoleAssignmentPolicy
 * (S1) already established for role changes. Unlike that policy, a blocked
 * attempt here writes no row at all: impersonation_audits (unlike
 * role_assignment_audits) has no `allowed` column — it only ever records
 * real, successful sessions, matching the design doc's own schema.
 */
class ImpersonationService
{
    /**
     * docs/FEATURES.md's A6 row claims this explicitly ("60-min
     * auto-expiry") — found unimplemented anywhere in S9 B3's own build
     * by the S9 B14 review pass: ResolveImpersonation had no time check
     * at all, so a session persisted until the underlying Laravel session
     * cookie itself expired (SESSION_LIFETIME, unrelated and longer),
     * not a deliberate impersonation-specific cutoff. Fixed here, not
     * flagged-and-deferred, since a hardening slice's own review pass
     * finding an unbounded elevated-privilege session is exactly the
     * class of gap this slice exists to close, and the fix is small and
     * well-contained (unlike, say, D8's "overage billing" or T3's
     * "rehearsed restore," both flagged in the plan doc's own Post-S9
     * review section instead — genuinely bigger, more product-shaped
     * work, not a contained middleware check).
     */
    public const MAX_DURATION_MINUTES = 60;

    public function start(User $actor, User $target): ImpersonationAudit
    {
        if ($target->hasRole('super_admin')) {
            throw CannotImpersonateException::superAdminTarget();
        }

        $audit = ImpersonationAudit::create([
            'tenant_id' => $target->tenant_id,
            'actor_id' => $actor->id,
            'target_user_id' => $target->id,
            'started_at' => now(),
        ]);

        session([
            'impersonating_user_id' => $target->id,
            'impersonation_audit_id' => $audit->id,
            'impersonator_id' => $actor->id,
        ]);

        return $audit;
    }

    public function stop(): void
    {
        $auditId = session('impersonation_audit_id');

        if ($auditId !== null) {
            ImpersonationAudit::whereKey($auditId)
                ->whereNull('ended_at')
                ->update(['ended_at' => now()]);
        }

        session()->forget(['impersonating_user_id', 'impersonation_audit_id', 'impersonator_id']);
    }
}
