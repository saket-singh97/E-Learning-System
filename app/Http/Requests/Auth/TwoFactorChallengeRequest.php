<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * S12 B13 (design doc §2.2, V4) — the second of this app's two remaining
 * inline `$request->validate()` calls, extracted into a Form Request
 * matching `LoginRequest`'s own shape (S1).
 */
class TwoFactorChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string'],
        ];
    }
}
