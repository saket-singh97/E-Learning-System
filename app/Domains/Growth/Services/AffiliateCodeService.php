<?php

namespace App\Domains\Growth\Services;

use App\Domains\Growth\Models\AffiliateCode;
use App\Models\User;

/**
 * The one place an affiliate code is minted — same "one shared path, no
 * ad hoc shortcut" posture `ParentInviteCodeService` already established
 * for its own generated code.
 */
class AffiliateCodeService
{
    /**
     * Excludes 0/O and 1/I/L — same reasoning `ParentInviteCodeService`
     * already gives: a human re-typing this shouldn't have to guess which
     * glyph a given character actually is.
     */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function createFor(User $owner, string $commissionType, float $commissionValue): AffiliateCode
    {
        return AffiliateCode::create([
            'owner_user_id' => $owner->id,
            'code' => $this->generateUniqueCode(),
            'commission_type' => $commissionType,
            'commission_value' => $commissionValue,
            'status' => 'active',
        ]);
    }

    /**
     * Unique per tenant (design doc §3.2), not globally — the DB's own
     * unique index is `(tenant_id, code)`, so this check relies on the
     * normal `BelongsToTenant` scope already being bound (generation
     * always happens inside a real tenant context), unlike
     * `ParentInviteCodeService`'s own generator, which has to check
     * cross-tenant because its own code is globally unique instead.
     */
    protected function generateUniqueCode(): string
    {
        do {
            $code = collect(range(1, 8))
                ->map(fn (): string => self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)])
                ->implode('');
        } while (AffiliateCode::where('code', $code)->exists());

        return $code;
    }
}
