<?php

namespace App\Domains\Tenancy\Services;

use App\Domains\Batches\Models\Batch;
use App\Domains\Tenancy\Models\InstructorInvitation;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Policies\RoleAssignmentPolicy;
use App\Models\User;
use App\Notifications\InstructorInvitationMail;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Closes docs/FEATURES.md §S.S1's real gap (design doc §2.3) — same "one
 * shared path, no ad hoc shortcut" posture ParentInviteCodeService already
 * established for a structurally similar token+expiry+redemption flow.
 */
class InstructorInvitationService
{
    private const EXPIRES_IN_DAYS = 14;

    /**
     * @param  array<int, int>  $batchIds
     */
    public function invite(string $email, array $batchIds, User $actor): InstructorInvitation
    {
        $invitation = InstructorInvitation::create([
            'email' => $email,
            'batch_ids' => $batchIds,
            'token' => Str::random(64),
            'invited_by_user_id' => $actor->id,
            'status' => 'pending',
            'expires_at' => now()->addDays(self::EXPIRES_IN_DAYS),
        ]);

        // On-demand notification (no User row exists yet for the invitee)
        // — the first such call site in this app (every existing
        // Notification::send/->notify() call targets an already-created
        // User; ParentInviteCodeService's own Filament page never emails
        // at all, it just displays the code for the admin to share).
        Notification::route('mail', $email)->notify(new InstructorInvitationMail($invitation));

        return $invitation;
    }

    /**
     * Deliberately does not read CurrentTenant anywhere, same reasoning
     * ParentInviteCodeService::redeem() already documents — this accept
     * link is host-agnostic (routes/web.php), the token alone carries the
     * tenant.
     *
     * @param  array{name?: string, password?: string}  $userAttributes
     */
    public function accept(string $token, array $userAttributes): User
    {
        $invitation = InstructorInvitation::withoutGlobalScope('tenant')
            ->where('token', $token)
            ->first();

        if ($invitation === null) {
            throw ValidationException::withMessages(['token' => 'That invitation was not found.']);
        }

        if ($invitation->status !== 'pending') {
            throw ValidationException::withMessages(['token' => 'That invitation is no longer valid.']);
        }

        if ($invitation->isExpired()) {
            $invitation->update(['status' => 'expired']);

            throw ValidationException::withMessages(['token' => 'That invitation has expired.']);
        }

        // InstructorInvitation's own update above (status), the User
        // create below, and RoleAssignmentAudit (written by
        // RoleAssignmentPolicy::assign()) all use BelongsToTenant, whose
        // creating() hook unconditionally reads CurrentTenant::id() and
        // throws when unbound — exactly this accept page's normal state.
        // Rebind to the invitation's own tenant for this call only, then
        // restore whatever was bound before.
        $currentTenant = app(CurrentTenant::class);
        $previousTenant = $currentTenant->isBound() ? $currentTenant->get() : null;
        $currentTenant->set(Tenant::findOrFail($invitation->tenant_id));

        try {
            return DB::transaction(function () use ($invitation, $userAttributes): User {
                // users.email is globally unique — an existing row under a
                // *different* tenant means this email is already spoken
                // for elsewhere and can't be linked here.
                $user = User::where('email', $invitation->email)->first();

                if ($user !== null && $user->tenant_id !== $invitation->tenant_id) {
                    throw ValidationException::withMessages([
                        'token' => 'This email is already registered under a different institute.',
                    ]);
                }

                if ($user === null) {
                    $user = User::create([
                        'tenant_id' => $invitation->tenant_id,
                        'name' => $userAttributes['name'] ?? '',
                        'email' => $invitation->email,
                        'password' => $userAttributes['password'] ?? Str::random(32),
                        'is_active' => true,
                    ]);
                }

                app(RoleAssignmentPolicy::class)->assign($invitation->invitedBy, $user, 'instructor');

                Batch::whereIn('id', $invitation->batch_ids ?? [])->update(['instructor_id' => $user->id]);

                $invitation->update([
                    'status' => 'accepted',
                    'accepted_user_id' => $user->id,
                ]);

                return $user;
            });
        } finally {
            if ($previousTenant !== null) {
                $currentTenant->set($previousTenant);
            } else {
                $currentTenant->clear();
            }
        }
    }

    public function deactivate(User $instructor): void
    {
        $instructor->update(['is_active' => false]);
    }

    public function reactivate(User $instructor): void
    {
        $instructor->update(['is_active' => true]);
    }
}
