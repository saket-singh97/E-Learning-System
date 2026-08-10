<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

class PasswordResetLinkController extends Controller
{
    public function store(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::sendResetLink([
            'email' => $request->string('email'),
            'tenant_id' => app(CurrentTenant::class)->id(),
        ]);

        return $status === PasswordBroker::RESET_LINK_SENT
            ? response()->json(['status' => __($status)])
            : response()->json(['status' => __($status)], 422);
    }
}
