<?php

namespace App\Domains\Comms\Services;

use App\Domains\Comms\Models\ParentGuardianLink;
use App\Domains\Comms\Models\ParentInviteCode;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Policies\RoleAssignmentPolicy;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one place an invite code is generated and redeemed — same
 * "one shared path, no ad hoc shortcut" posture every other sensitive
 * action in this app follows (RoleAssignmentPolicy, TenantAuthenticator).
 */
class ParentInviteCodeService
{
    private const EXPIRES_IN_DAYS = 7;

    /**
     * Excludes 0/O and 1/I/L — a human re-typing this from a WhatsApp
     * message or a printed slip shouldn't have to guess which glyph a
     * given character actually is.
     */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function generate(User $student, User $creator): ParentInviteCode
    {
        return ParentInviteCode::create([
            'student_user_id' => $student->id,
            'code' => $this->generateUniqueCode(),
            'expires_at' => now()->addDays(self::EXPIRES_IN_DAYS),
            'created_by' => $creator->id,
        ]);
    }

    /**
     * Deliberately does not read CurrentTenant anywhere — the new parent's
     * tenant_id comes from the code's own student, not from whatever host
     * happens to render the redemption form (this block's own plan note:
     * the form is reachable regardless of subdomain, the code alone
     * carries the tenant).
     *
     * @param  array{name: string, email: string, phone: string, password: string, relationship: ?string}  $parentAttributes
     */
    public function redeem(string $code, array $parentAttributes): User
    {
        // withoutGlobalScope('tenant'): the redemption page is
        // host-agnostic (this block's own plan note) — CurrentTenant may
        // be unbound, or bound to a tenant that has nothing to do with
        // the code's own issuing tenant, depending on which subdomain (if
        // any) happens to render the form. The code is the only thing
        // that determines the tenant here.
        $inviteCode = ParentInviteCode::withoutGlobalScope('tenant')
            ->where('code', strtoupper(trim($code)))
            ->first();

        if ($inviteCode === null) {
            throw ValidationException::withMessages(['code' => 'That invite code was not found.']);
        }

        if ($inviteCode->isRedeemed()) {
            throw ValidationException::withMessages(['code' => 'That invite code has already been used.']);
        }

        if ($inviteCode->isExpired()) {
            throw ValidationException::withMessages(['code' => 'That invite code has expired.']);
        }

        // ParentGuardianLink and RoleAssignmentAudit (written by
        // RoleAssignmentPolicy::assign() below) both use BelongsToTenant,
        // whose creating() hook unconditionally reads CurrentTenant::id()
        // and throws when unbound — exactly this redemption page's normal
        // state. Rebind to the invite's own tenant for this call only, same
        // pattern RebindsTenant already uses for queued jobs with no
        // request-derived tenant of their own, then restore whatever was
        // bound before so this call never leaks tenant state into its
        // caller.
        $currentTenant = app(CurrentTenant::class);
        $previousTenant = $currentTenant->isBound() ? $currentTenant->get() : null;

        $currentTenant->set(Tenant::findOrFail($inviteCode->tenant_id));

        try {
            return DB::transaction(function () use ($inviteCode, $parentAttributes): User {
                $parent = User::create([
                    'tenant_id' => $inviteCode->tenant_id,
                    'name' => $parentAttributes['name'],
                    'email' => $parentAttributes['email'],
                    'phone' => $parentAttributes['phone'],
                    'password' => $parentAttributes['password'],
                ]);

                // The invite's own creator (always an institute_admin —
                // this block's generation UI is Admin-panel-only) is the
                // actor: generating the code is itself the delegation, the
                // same way CreateParentGuardian's own institute_admin actor
                // grants the role directly. Same audit-trail discipline, no
                // bypass just because this entry point has no logged-in
                // actor of its own.
                app(RoleAssignmentPolicy::class)->assign($inviteCode->creator, $parent, 'parent');

                ParentGuardianLink::create([
                    'parent_user_id' => $parent->id,
                    'student_user_id' => $inviteCode->student_user_id,
                    'relationship' => $parentAttributes['relationship'] ?? null,
                ]);

                $inviteCode->update([
                    'redeemed_at' => now(),
                    'redeemed_by' => $parent->id,
                ]);

                return $parent;
            });
        } finally {
            if ($previousTenant !== null) {
                $currentTenant->set($previousTenant);
            } else {
                $currentTenant->clear();
            }
        }
    }

    protected function generateUniqueCode(): string
    {
        do {
            $code = collect(range(1, 8))
                ->map(fn (): string => self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)])
                ->implode('');
            // code is globally unique (design doc §3.1), not per-tenant —
            // the DB's own unique index has no tenant_id in it, so this
            // check must see every tenant's codes too, not just the
            // current one.
        } while (ParentInviteCode::withoutGlobalScope('tenant')->where('code', $code)->exists());

        return $code;
    }
}
