<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterUserRequest;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class RegisteredUserController extends Controller
{
    public function store(RegisterUserRequest $request): JsonResponse
    {
        $user = User::create([
            'tenant_id' => app(CurrentTenant::class)->id(),
            'name' => $request->string('name'),
            'email' => $request->string('email'),
            'password' => $request->string('password')->value(),
        ]);

        $user->sendEmailVerificationNotification();

        Auth::login($user);

        return response()->json(['id' => $user->id, 'email' => $user->email], 201);
    }
}
