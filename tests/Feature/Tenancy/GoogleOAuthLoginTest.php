<?php

use App\Domains\Tenancy\Models\Tenant;
use App\Livewire\StudentPortal;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function fakeSocialiteUser(string $googleId, string $email, string $name = 'Ada Lovelace'): SocialiteUser
{
    return (new SocialiteUser)->setRaw([])->map([
        'id' => $googleId,
        'name' => $name,
        'email' => $email,
    ]);
}

function mockSocialiteGoogle(SocialiteUser $user): void
{
    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($user);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
}

it('starts the redirect on the tenants own subdomain and remembers the tenant and portal in session', function () {
    Tenant::factory()->create(['subdomain' => 'physics']);

    $response = $this->get('http://physics.lms.test/auth/google/redirect/student');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('accounts.google.com');
    expect(session('oauth.portal'))->toBe('student');
});

it('404s an unrecognised portal segment', function () {
    Tenant::factory()->create(['subdomain' => 'physics']);

    $this->get('http://physics.lms.test/auth/google/redirect/instructor')->assertNotFound();
});

it('creates a new user attached to the tenant that started the flow, and logs them in', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);

    $this->withSession([
        'oauth.tenant_id' => $tenant->id,
        'oauth.portal' => 'student',
    ]);

    mockSocialiteGoogle(fakeSocialiteUser('google-123', 'ada@example.com'));

    $response = $this->get('http://lms.test/auth/google/callback');

    $user = User::where('email', 'ada@example.com')->firstOrFail();

    expect($user->tenant_id)->toBe($tenant->id);
    expect($user->google_id)->toBe('google-123');
    expect($user->email_verified_at)->not->toBeNull();
    $this->assertAuthenticatedAs($user);
    $response->assertRedirect("http://physics.lms.test/dashboard");
});

it('redirects a parent-portal signup to the parent dashboard instead', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);

    $this->withSession([
        'oauth.tenant_id' => $tenant->id,
        'oauth.portal' => 'parent',
    ]);

    mockSocialiteGoogle(fakeSocialiteUser('google-456', 'priya@example.com'));

    $this->get('http://lms.test/auth/google/callback')
        ->assertRedirect('http://physics.lms.test/parent/dashboard');
});

it('logs in an existing user matched by a previously-linked google_id, without creating a duplicate', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $existing = User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'ada@example.com',
        'google_id' => 'google-123',
    ]);

    $this->withSession(['oauth.tenant_id' => $tenant->id, 'oauth.portal' => 'student']);

    mockSocialiteGoogle(fakeSocialiteUser('google-123', 'ada@example.com'));

    $this->get('http://lms.test/auth/google/callback');

    $this->assertAuthenticatedAs($existing);
    expect(User::where('email', 'ada@example.com')->count())->toBe(1);
});

it('links google_id onto an existing password-based account matched by email in the same tenant, instead of duplicating it', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $existing = User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'ada@example.com',
        'password' => bcrypt('secret-password'),
    ]);

    $this->withSession(['oauth.tenant_id' => $tenant->id, 'oauth.portal' => 'student']);

    mockSocialiteGoogle(fakeSocialiteUser('google-789', 'ada@example.com'));

    $this->get('http://lms.test/auth/google/callback');

    $this->assertAuthenticatedAs($existing);
    expect($existing->fresh()->google_id)->toBe('google-789');
    expect(User::where('email', 'ada@example.com')->count())->toBe(1);
});

it('does not link across tenants: the same email in a different tenant stays a separate account', function () {
    $physics = Tenant::factory()->create(['subdomain' => 'physics']);
    $chemistry = Tenant::factory()->create(['subdomain' => 'chemistry']);

    $physicsStudent = User::factory()->create(['tenant_id' => $physics->id, 'email' => 'shared@example.com']);
    User::factory()->create(['tenant_id' => $chemistry->id, 'email' => 'shared@example.com']);

    $this->withSession(['oauth.tenant_id' => $chemistry->id, 'oauth.portal' => 'student']);

    mockSocialiteGoogle(fakeSocialiteUser('google-999', 'shared@example.com'));

    $this->get('http://lms.test/auth/google/callback');

    $this->assertAuthenticated();
    expect(auth()->id())->not->toBe($physicsStudent->id);
    expect($physicsStudent->fresh()->google_id)->toBeNull();
});

it('aborts the callback when the session has no pending oauth tenant (expired or direct visit)', function () {
    Tenant::factory()->create(['subdomain' => 'physics']);

    $this->get('http://lms.test/auth/google/callback')->assertStatus(419);
});

it('does not log in a user with two-factor enabled — hands off to the portals own challenge instead (S9 B14)', function () {
    // Found bypassed entirely by the S9 B14 review pass: this callback
    // used to call Auth::login() unconditionally, so 2FA (S9 B1) was
    // silently skippable just by using the Google button — Google only
    // proves identity, not possession of the second factor.
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $student = User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'ada@example.com',
        'google_id' => 'google-123',
    ]);
    enableTwoFactor($student);

    $this->withSession(['oauth.tenant_id' => $tenant->id, 'oauth.portal' => 'student']);
    mockSocialiteGoogle(fakeSocialiteUser('google-123', 'ada@example.com'));

    $this->get('http://lms.test/auth/google/callback')
        ->assertRedirect('http://physics.lms.test/dashboard');

    $this->assertGuest();
    expect(session('oauth.pending_two_factor_user_id'))->toBe($student->id);
});

it('completes login via the portals own two-factor challenge after a 2FA-enabled Google sign-in', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $student = User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'ada@example.com',
        'google_id' => 'google-123',
    ]);
    [$secret] = enableTwoFactor($student);

    $this->withSession(['oauth.tenant_id' => $tenant->id, 'oauth.portal' => 'student']);
    mockSocialiteGoogle(fakeSocialiteUser('google-123', 'ada@example.com'));

    $this->get('http://lms.test/auth/google/callback');
    $this->assertGuest();

    app(CurrentTenant::class)->set($tenant);

    $component = Livewire::test(StudentPortal::class);
    $component->assertSet('twoFactorUserId', $student->id);

    $code = app(AppAuthentication::class)->getCurrentCode($student, $secret);

    $component->set('twoFactorCode', $code)->call('verifyTwoFactorCode');

    $this->assertAuthenticatedAs($student);
});

it('does not resume a two-factor challenge for a different tenant than the one the session flag was set for', function () {
    $physics = Tenant::factory()->create(['subdomain' => 'physics']);
    $chemistry = Tenant::factory()->create(['subdomain' => 'chemistry']);
    $student = User::factory()->create(['tenant_id' => $physics->id, 'email' => 'ada@example.com', 'google_id' => 'google-123']);
    enableTwoFactor($student);

    $this->withSession(['oauth.tenant_id' => $physics->id, 'oauth.portal' => 'student']);
    mockSocialiteGoogle(fakeSocialiteUser('google-123', 'ada@example.com'));
    $this->get('http://lms.test/auth/google/callback');

    app(CurrentTenant::class)->set($chemistry);

    Livewire::test(StudentPortal::class)
        ->assertSet('twoFactorUserId', null);
});
