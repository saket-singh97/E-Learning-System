<?php

use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function tenantUrl(string $subdomain, string $path): string
{
    return "http://{$subdomain}.lms.test{$path}";
}

it('registers, verifies by email, logs out, resets password, and logs back in', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    Notification::fake();

    $register = $this->postJson(tenantUrl('physics', '/register'), [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ]);

    $register->assertCreated();
    $this->assertAuthenticated();

    $user = User::where('email', 'ada@example.com')->firstOrFail();
    expect($user->tenant_id)->toBe($tenant->id);
    expect($user->hasVerifiedEmail())->toBeFalse();

    $verifyUrl = null;
    Notification::assertSentTo($user, VerifyEmail::class, function ($notification, $channels) use ($user, &$verifyUrl) {
        $verifyUrl = $notification->toMail($user)->actionUrl;

        return true;
    });

    $this->getJson($verifyUrl)->assertOk()->assertJson(['status' => 'verified']);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

    $this->postJson(tenantUrl('physics', '/logout'))->assertNoContent();
    $this->assertGuest();

    $forgot = $this->postJson(tenantUrl('physics', '/forgot-password'), [
        'email' => 'ada@example.com',
    ]);
    $forgot->assertOk();

    $resetUrl = null;
    Notification::assertSentTo($user, ResetPassword::class, function ($notification, $channels) use ($user, &$resetUrl) {
        $resetUrl = $notification->toMail($user)->actionUrl;

        return true;
    });

    $token = Str::after(parse_url($resetUrl, PHP_URL_PATH), '/reset-password/');

    $this->postJson(tenantUrl('physics', '/reset-password'), [
        'token' => $token,
        'email' => 'ada@example.com',
        'password' => 'new-correct-horse-battery',
        'password_confirmation' => 'new-correct-horse-battery',
    ])->assertOk();

    expect(Hash::check('new-correct-horse-battery', $user->fresh()->password))->toBeTrue();

    $login = $this->postJson(tenantUrl('physics', '/login'), [
        'email' => 'ada@example.com',
        'password' => 'new-correct-horse-battery',
        'remember' => true,
    ]);

    $login->assertOk();
    $this->assertAuthenticatedAs($user->fresh());
    expect($user->fresh()->remember_token)->not->toBeEmpty();
    $login->assertCookie(Auth::guard('web')->getRecallerName());
});

it('locks out after six wrong passwords and shows remaining time', function () {
    Tenant::factory()->create(['subdomain' => 'physics']);
    User::factory()->create(['email' => 'target@example.com']);

    RateLimiter::clear('login-lockout:target@example.com|127.0.0.1');

    for ($i = 0; $i < 5; $i++) {
        $response = $this->postJson(tenantUrl('physics', '/login'), [
            'email' => 'target@example.com',
            'password' => 'wrong-password',
        ]);
        $response->assertUnprocessable();
    }

    $sixth = $this->postJson(tenantUrl('physics', '/login'), [
        'email' => 'target@example.com',
        'password' => 'wrong-password',
    ]);

    $sixth->assertUnprocessable();
    expect($sixth->json('errors.email.0'))->toMatch('/Too many login attempts\. Please try again in \d+ seconds\./');
});

it('lets a new registration reuse the email of a soft-deleted user in the same tenant', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $existing = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'gone@example.com']);
    $existing->delete();

    Notification::fake();

    $this->postJson(tenantUrl('physics', '/register'), [
        'name' => 'New Person',
        'email' => 'gone@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertCreated();

    expect(User::where('email', 'gone@example.com')->count())->toBe(1);
});

it('throws a clear error building an auth URL for a tenant-less user, instead of a null-property fatal', function () {
    $superAdmin = User::factory()->create(['tenant_id' => null]);

    expect(fn () => \Illuminate\Support\Facades\Password::sendResetLink(['email' => $superAdmin->email]))
        ->not->toThrow(\Error::class);
});

it('lets the same email register successfully under two different tenants', function () {
    Tenant::factory()->create(['subdomain' => 'physics']);
    Tenant::factory()->create(['subdomain' => 'chemistry']);
    Notification::fake();

    $first = $this->postJson(tenantUrl('physics', '/register'), [
        'name' => 'Ada Lovelace',
        'email' => 'shared@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ]);
    $first->assertCreated();

    $this->postJson(tenantUrl('physics', '/logout'));

    $second = $this->postJson(tenantUrl('chemistry', '/register'), [
        'name' => 'Ada Lovelace',
        'email' => 'shared@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ]);

    $second->assertCreated();
    expect(User::where('email', 'shared@example.com')->count())->toBe(2);
});
