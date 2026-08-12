<?php

namespace App\Domains\Tenancy\Services;

class RoleHierarchy
{
    /**
     * Lower number = higher authority.
     *
     * parent sits below student, not tied with it (S8: the parent portal
     * only ever reads a linked student's own data, never acts as or above
     * a student). Splitting them doesn't change any assignment decision
     * today — neither role can assign roles at all (see CAN_ASSIGN_ROLES)
     * — but it removes the ambiguity a tie left open for any future
     * feature that compares the two roles' levels directly.
     */
    public const LEVELS = [
        'super_admin' => 1,
        'institute_admin' => 2,
        'instructor' => 3,
        'student' => 4,
        'parent' => 5,
    ];

    /**
     * Role assignment is an admin-tier action. instructor/student/parent
     * can never assign a role, regardless of the target — this is what
     * makes "an instructor cannot assign any role" true, on top of the
     * downward-only hierarchy check below.
     */
    public const CAN_ASSIGN_ROLES = ['super_admin', 'institute_admin'];

    /**
     * True only when the actor may assign $targetRole: the actor must be an
     * admin-tier role, and the target role must be strictly below the
     * actor's own level (assigning at-or-above own level is escalation).
     */
    public static function canAssign(string $actorRole, string $targetRole): bool
    {
        if (! in_array($actorRole, self::CAN_ASSIGN_ROLES, true)) {
            return false;
        }

        if (! isset(self::LEVELS[$actorRole], self::LEVELS[$targetRole])) {
            return false;
        }

        return self::LEVELS[$targetRole] > self::LEVELS[$actorRole];
    }

    /**
     * The actor's effective role when comparing hierarchy: the highest
     * privilege (lowest level number) among any roles they hold. Returns
     * null if they hold none.
     *
     * @param  array<int, string>  $roleNames
     */
    public static function highestRole(array $roleNames): ?string
    {
        $known = array_values(array_filter($roleNames, fn (string $role) => isset(self::LEVELS[$role])));

        if ($known === []) {
            return null;
        }

        usort($known, fn (string $a, string $b) => self::LEVELS[$a] <=> self::LEVELS[$b]);

        return $known[0];
    }
}
