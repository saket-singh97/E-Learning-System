<?php

namespace App\Http\Requests\Auth;

use App\Domains\Tenancy\Services\TenantAuthenticator;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Credential + lockout check only — does not log in. S9 B1 split this
     * from a single attempt()-and-login step so the controller gets a
     * chance to detour into a two-factor challenge for a user who has one
     * enabled, same reasoning as every other login surface in this app.
     */
    public function authenticate(): User
    {
        return app(TenantAuthenticator::class)->validate(
            email: $this->string('email')->value(),
            password: $this->string('password')->value(),
            tenantId: app(CurrentTenant::class)->id(),
            ip: $this->ip(),
        );
    }
}
