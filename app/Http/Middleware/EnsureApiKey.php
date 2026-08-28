<?php

namespace App\Http\Middleware;

use App\Domains\Api\Services\ApiKeyService;
use App\Exceptions\InvalidApiKeyException;
use App\Support\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * S21 B4 (V12b) — an institute's own `api_keys` row, not a Sanctum user
 * token: this is machine-to-machine (the institute's external system
 * calling us), no user session involved. Binds CurrentTenant the same way
 * ResolveTenant does for the subdomain path, so a handler downstream sees
 * the calling institute's own tenant scope.
 */
class EnsureApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainKey = $request->bearerToken();

        $key = $plainKey ? app(ApiKeyService::class)->verify($plainKey) : null;

        if (! $key) {
            throw InvalidApiKeyException::make();
        }

        app(CurrentTenant::class)->set($key->tenant);

        return $next($request);
    }
}
