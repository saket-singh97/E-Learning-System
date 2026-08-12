<?php

use App\Domains\Tenancy\Models\Tenant;
use App\Livewire\ParentPortal;
use App\Livewire\StudentPortal;
use App\Livewire\TwoFactorSettings;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function enableTwoFactor(User $user): array
{
    $appAuthentication = app(AppAuthentication::class);

    $secret = $appAuthentication->generateSecret();
    $recoveryCodes = $appAuthentication->generateRecoveryCodes();

    $appAuthentication->saveSecret($user, $secret);
    $appAuthentication->saveRecoveryCodes($user, $recoveryCodes);

    return [$secret, $recoveryCodes];
}

it('enrolls a student in two-factor via the shared settings component and issues recovery codes', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    app(CurrentTenant::class)->set($tenant);
    $this->actingAs($student);

    $component = Livewire::test(TwoFactorSettings::class);

    $secret = $component->get('secret');
    expect($secret)->not->toBeEmpty();

    $code = app(AppAuthentication::class)->getCurrentCode($student, $secret);

    $component->set('confirmationCode', $code)
        ->call('confirm')
        ->assertHasNoErrors();

    expect($student->fresh()->hasTwoFactorEnabled())->toBeTrue();
    expect($component->get('recoveryCodes'))->toHaveCount(8);
});

it('rejects an incorrect confirmation code during enrollment, leaving 2FA off', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    app(CurrentTenant::class)->set($tenant);
    $this->actingAs($student);

    Livewire::test(TwoFactorSettings::class)
        ->set('confirmationCode', '000000')
        ->call('confirm')
        ->assertHasErrors('confirmationCode');

    expect($student->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('disables two-factor via the settings component given a valid code', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');
    [$secret] = enableTwoFactor($student);

    app(CurrentTenant::class)->set($tenant);
    $this->actingAs($student);

    $code = app(AppAuthentication::class)->getCurrentCode($student, $secret);

    Livewire::test(TwoFactorSettings::class)
        ->set('disableCode', $code)
        ->call('disable')
        ->assertHasNoErrors();

    expect($student->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('challenges a student with 2FA enabled instead of logging them in on password success', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $student = User::factory()->create(['tenant_id' => $tenant->id, 'password' => bcrypt('secret-password')]);
    [$secret] = enableTwoFactor($student);

    app(CurrentTenant::class)->set($tenant);

    $component = Livewire::test(StudentPortal::class)
        ->set('email', $student->email)
        ->set('password', 'secret-password')
        ->call('login');

    $this->assertGuest();
    $component->assertSet('twoFactorUserId', $student->id);

    $code = app(AppAuthentication::class)->getCurrentCode($student, $secret);

    $component->set('twoFactorCode', $code)
        ->call('verifyTwoFactorCode');

    $this->assertAuthenticatedAs($student);
});

it('lets a student log in with a recovery code instead of a TOTP code, and the code cannot be reused', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $student = User::factory()->create(['tenant_id' => $tenant->id, 'password' => bcrypt('secret-password')]);
    [, $recoveryCodes] = enableTwoFactor($student);
    $recoveryCode = $recoveryCodes[0];

    app(CurrentTenant::class)->set($tenant);

    $component = Livewire::test(StudentPortal::class)
        ->set('email', $student->email)
        ->set('password', 'secret-password')
        ->call('login');

    $component->set('twoFactorCode', $recoveryCode)
        ->call('verifyTwoFactorCode');

    $this->assertAuthenticatedAs($student);
    Auth::logout();

    // Same recovery code, second time: password step passes again but the
    // now-consumed code must fail the challenge.
    $second = Livewire::test(StudentPortal::class)
        ->set('email', $student->email)
        ->set('password', 'secret-password')
        ->call('login');

    $second->set('twoFactorCode', $recoveryCode)
        ->call('verifyTwoFactorCode')
        ->assertHasErrors('twoFactorCode');

    $this->assertGuest();
});

it('locks out a student after repeated wrong 2FA codes, independent of the password lockout', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $student = User::factory()->create(['tenant_id' => $tenant->id, 'password' => bcrypt('secret-password')]);
    enableTwoFactor($student);

    app(CurrentTenant::class)->set($tenant);

    $component = Livewire::test(StudentPortal::class)
        ->set('email', $student->email)
        ->set('password', 'secret-password')
        ->call('login');

    for ($i = 0; $i < 5; $i++) {
        $component->set('twoFactorCode', '000000')->call('verifyTwoFactorCode');
    }

    $component->assertHasErrors('twoFactorCode');
    $this->assertGuest();

    RateLimiter::clear('two-factor-lockout:'.$student->id);
});

it('challenges a parent with 2FA enabled the same way as a student', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $parent = User::factory()->create(['tenant_id' => $tenant->id, 'password' => bcrypt('secret-password')]);
    $parent->assignRole('parent');
    [$secret] = enableTwoFactor($parent);

    app(CurrentTenant::class)->set($tenant);

    $component = Livewire::test(ParentPortal::class)
        ->set('email', $parent->email)
        ->set('password', 'secret-password')
        ->call('login');

    $this->assertGuest();
    $component->assertSet('twoFactorUserId', $parent->id);

    $code = app(AppAuthentication::class)->getCurrentCode($parent, $secret);

    $component->set('twoFactorCode', $code)->call('verifyTwoFactorCode');

    $this->assertAuthenticatedAs($parent);
});

it('returns two_factor_required from the JSON login endpoint instead of a session, then verifies the code', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $student = User::factory()->create(['tenant_id' => $tenant->id, 'password' => bcrypt('secret-password')]);
    [$secret] = enableTwoFactor($student);

    app(CurrentTenant::class)->set($tenant);

    $response = $this->postJson("http://physics.lms.test/login", [
        'email' => $student->email,
        'password' => 'secret-password',
    ]);

    $response->assertOk()->assertJson(['two_factor_required' => true]);
    $this->assertGuest();

    $code = app(AppAuthentication::class)->getCurrentCode($student, $secret);

    $verify = $this->postJson('http://physics.lms.test/login/two-factor-challenge', ['code' => $code]);

    $verify->assertOk()->assertJson(['id' => $student->id]);
    $this->assertAuthenticatedAs($student);
});

it('rejects a wrong code on the JSON two-factor challenge endpoint', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $student = User::factory()->create(['tenant_id' => $tenant->id, 'password' => bcrypt('secret-password')]);
    enableTwoFactor($student);

    app(CurrentTenant::class)->set($tenant);

    $this->postJson('http://physics.lms.test/login', [
        'email' => $student->email,
        'password' => 'secret-password',
    ])->assertOk();

    $this->postJson('http://physics.lms.test/login/two-factor-challenge', ['code' => '000000'])
        ->assertUnprocessable();

    $this->assertGuest();

    RateLimiter::clear('two-factor-lockout:'.$student->id);
});

it('logs a student in normally with no 2FA challenge when it is not enabled', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $student = User::factory()->create(['tenant_id' => $tenant->id, 'password' => bcrypt('secret-password')]);

    app(CurrentTenant::class)->set($tenant);

    Livewire::test(StudentPortal::class)
        ->set('email', $student->email)
        ->set('password', 'secret-password')
        ->call('login')
        ->assertSet('twoFactorUserId', null);

    $this->assertAuthenticatedAs($student);
});
