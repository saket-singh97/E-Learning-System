<?php

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\WhatsAppLink;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Generates the one-time code a student texts to the platform's single
 * WhatsApp number to link their phone (design decision 1). The pending
 * code lives in cache, not a table — `whatsapp_links` only ever holds
 * confirmed rows (design §3.2's own `verified_at NOT NULL` shape), so a
 * short-lived cache entry is the whole "before the identity is confirmed"
 * state, nothing to clean up once it expires.
 *
 * `verifyCode()` is the step B1's inbound webhook calls once a code
 * arrives over WhatsApp; this block builds it as a directly callable
 * service method so B0's own tests can exercise it ahead of the webhook.
 */
class WhatsAppLinkService
{
    private const CODE_TTL_MINUTES = 15;

    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function generateCode(User $student): string
    {
        $code = $this->generateUniqueCode();

        Cache::put($this->cacheKey($code), [
            'tenant_id' => $student->tenant_id,
            'user_id' => $student->id,
        ], now()->addMinutes(self::CODE_TTL_MINUTES));

        return $code;
    }

    public function verifyCode(string $code, string $phoneE164): WhatsAppLink
    {
        $code = strtoupper(trim($code));
        $cacheKey = $this->cacheKey($code);

        // Peek, don't consume yet (B13 review pass) — a phone-already-linked
        // failure below is unrelated to whether the code itself is valid,
        // so a student who mistyped the number shouldn't have to go back
        // and generate a brand new code just to retry with the right one.
        $pending = Cache::get($cacheKey);

        if ($pending === null) {
            throw ValidationException::withMessages(['code' => 'That linking code is invalid or expired.']);
        }

        if (WhatsAppLink::withoutGlobalScope('tenant')->where('phone_e164', $phoneE164)->exists()) {
            throw ValidationException::withMessages(['phone_e164' => 'That phone number is already linked to an account.']);
        }

        // BelongsToTenant's creating() hook unconditionally overwrites
        // tenant_id from CurrentTenant, and this method runs from a
        // platform-wide webhook with no subdomain to resolve one from
        // (same reasoning ParentInviteCodeService::redeem() already
        // documents) — rebind to the code's own tenant for this call only.
        $currentTenant = app(CurrentTenant::class);
        $previousTenant = $currentTenant->isBound() ? $currentTenant->get() : null;
        $currentTenant->set(Tenant::findOrFail($pending['tenant_id']));

        try {
            // The exists() check above is TOCTOU-racy under two concurrent
            // LINK attempts for the same phone (B13 review pass) — the
            // phone_e164 unique index is the real guard; a second insert
            // losing that race surfaces here as the same clean
            // already-linked error instead of an uncaught 500.
            try {
                $link = WhatsAppLink::create([
                    'user_id' => $pending['user_id'],
                    'phone_e164' => $phoneE164,
                    'verified_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['phone_e164' => 'That phone number is already linked to an account.']);
            }
        } finally {
            if ($previousTenant !== null) {
                $currentTenant->set($previousTenant);
            } else {
                $currentTenant->clear();
            }
        }

        // Consumed only once fully successful — a code that failed on the
        // phone check above stays valid for the student to retry.
        Cache::forget($cacheKey);

        return $link;
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = collect(range(1, 8))
                ->map(fn (): string => self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)])
                ->implode('');
        } while (Cache::has($this->cacheKey($code)));

        return $code;
    }

    private function cacheKey(string $code): string
    {
        return 'whatsapp_link_code:'.$code;
    }
}
