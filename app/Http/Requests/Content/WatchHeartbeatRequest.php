<?php

namespace App\Http\Requests\Content;

use Illuminate\Foundation\Http\FormRequest;

/**
 * S12 B13 (design doc §2.2, V4) — the first of this app's two remaining
 * inline `$request->validate()` calls, extracted into a Form Request
 * matching `LoginRequest`'s own shape (S1). First Form Request outside
 * `Auth/` — namespaced `Content` to mirror the controller it belongs to,
 * same convention the domain layout already uses elsewhere.
 */
class WatchHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_s' => ['required', 'integer', 'min:0'],
            'end_s' => ['required', 'integer', 'gte:start_s'],
            'duration_s' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
