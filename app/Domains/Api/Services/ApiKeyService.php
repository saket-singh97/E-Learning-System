<?php

namespace App\Domains\Api\Services;

use App\Domains\Api\Models\ApiKey;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Support\Str;

class ApiKeyService
{
    /**
     * The plain key is returned once here and never stored — only its hash
     * (design doc §4 decision 4). A leaked database dump never yields a
     * usable key.
     *
     * @return array{plainKey: string, model: ApiKey}
     */
    public function issue(Tenant $tenant, string $name): array
    {
        $plainKey = 'lms_'.Str::random(40);

        $key = ApiKey::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'key_hash' => static::hash($plainKey),
        ]);

        return ['plainKey' => $plainKey, 'model' => $key];
    }

    public function revoke(ApiKey $key): void
    {
        $key->update(['revoked_at' => now()]);
    }

    /**
     * Bypasses the tenant scope on purpose — an inbound bearer token
     * arrives with no tenant context bound yet (that's what this call
     * resolves), same "explicit tenant_id lookup" shape FeatureGate
     * already uses from an unscoped position.
     */
    public function verify(string $plainKey): ?ApiKey
    {
        $key = ApiKey::withoutGlobalScope('tenant')
            ->where('key_hash', static::hash($plainKey))
            ->whereNull('revoked_at')
            ->first();

        $key?->update(['last_used_at' => now()]);

        return $key;
    }

    public static function hash(string $plainKey): string
    {
        return hash('sha256', $plainKey);
    }
}
