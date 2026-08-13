<?php

use App\Domains\Tenancy\Models\Tenant;
use App\Livewire\UserProfile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
});

it('mounts on the student dashboard', function () {
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $student->assignRole('student');

    $this->actingAs($student)
        ->get('http://physics.lms.test/dashboard')
        ->assertOk()
        ->assertSee('My profile');
});

it('mounts on the parent dashboard', function () {
    $parent = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $parent->assignRole('parent');

    $this->actingAs($parent)
        ->get('http://physics.lms.test/parent/dashboard')
        ->assertOk()
        ->assertSee('My profile');
});

it('mounts on the admin panel', function () {
    $admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $admin->assignRole('institute_admin');

    $this->actingAs($admin)->get('http://physics.lms.test/admin')->assertOk()->assertSee('My profile');
});

it('mounts on the teach panel', function () {
    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');

    $this->actingAs($instructor)->get('http://physics.lms.test/teach')->assertOk()->assertSee('My profile');
});

it('mounts on the super panel', function () {
    // A separate test, not folded into the admin/teach cases above: the
    // bare lms.test host has no tenant, and CurrentTenant is a singleton
    // that persists across multiple requests within one test (NotificationCentreMountedTest's
    // own comment documents this same trap) — visiting a tenant-bound
    // panel first would leave a stale tenant bound, tripping EnsureRole's
    // cross-tenant check on this request and producing a 403.
    $superAdmin = User::factory()->create(['tenant_id' => null]);
    $superAdmin->assignRole('super_admin');

    $this->actingAs($superAdmin)->get('http://lms.test/super')->assertOk()->assertSee('My profile');
});

it('shows the two-factor entry point on a portal but not inside a Filament panel', function () {
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $student->assignRole('student');

    $this->actingAs($student)
        ->get('http://physics.lms.test/dashboard')
        ->assertOk()
        ->assertSee('Two-factor authentication');

    $admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $admin->assignRole('institute_admin');

    // Filament's own EditProfile page already carries 2FA for panel users
    // (S9 B1) — this component must not render a second, competing control.
    $this->actingAs($admin)
        ->get('http://physics.lms.test/admin')
        ->assertOk()
        ->assertDontSee('Two-factor authentication');
});

it('renders nothing for a guest without crashing', function () {
    Tenant::factory()->create(['subdomain' => 'chemistry']);

    $this->get('http://chemistry.lms.test/dashboard')
        ->assertOk()
        ->assertDontSee('My profile');
});

it('prefills name, timezone, and locale from the authenticated user', function () {
    $student = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Ada Lovelace',
        'timezone' => 'Europe/London',
        'locale' => 'en',
    ]);
    $student->assignRole('student');

    $this->actingAs($student);

    Livewire::test(UserProfile::class)
        ->assertSet('name', 'Ada Lovelace')
        ->assertSet('timezone', 'Europe/London')
        ->assertSet('locale', 'en');
});

it('updates name, timezone, and locale', function () {
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $student->assignRole('student');

    $this->actingAs($student);

    Livewire::test(UserProfile::class)
        ->set('name', 'New Name')
        ->set('timezone', 'Asia/Tokyo')
        ->set('locale', 'hi')
        ->call('updateProfile')
        ->assertHasNoErrors();

    $student->refresh();
    expect($student->name)->toBe('New Name')
        ->and($student->timezone)->toBe('Asia/Tokyo')
        ->and($student->locale)->toBe('hi');
});

it('rejects an invalid timezone', function () {
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $student->assignRole('student');

    $this->actingAs($student);

    Livewire::test(UserProfile::class)
        ->set('name', 'Someone')
        ->set('timezone', 'Not/A/Real/Zone')
        ->set('locale', 'en')
        ->call('updateProfile')
        ->assertHasErrors('timezone');
});

it('uploads and stores an avatar, replacing the previous file', function () {
    Storage::fake('public');

    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $student->assignRole('student');

    $this->actingAs($student);

    Livewire::test(UserProfile::class)
        ->set('name', 'Ada Lovelace')
        ->set('timezone', 'Asia/Kolkata')
        ->set('locale', 'en')
        ->set('avatarUpload', UploadedFile::fake()->image('me.png'))
        ->call('updateProfile')
        ->assertHasNoErrors();

    $student->refresh();
    expect($student->avatar_path)->toBe("avatars/{$student->id}/avatar.png");
    Storage::disk('public')->assertExists($student->avatar_path);

    $firstPath = $student->avatar_path;

    Livewire::test(UserProfile::class)
        ->set('name', 'Ada Lovelace')
        ->set('timezone', 'Asia/Kolkata')
        ->set('locale', 'en')
        ->set('avatarUpload', UploadedFile::fake()->image('me2.jpg'))
        ->call('updateProfile')
        ->assertHasNoErrors();

    $student->refresh();
    expect($student->avatar_path)->toBe("avatars/{$student->id}/avatar.jpg");
    Storage::disk('public')->assertMissing($firstPath);
});

it('rejects a disallowed avatar file type', function () {
    Storage::fake('public');

    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $student->assignRole('student');

    $this->actingAs($student);

    Livewire::test(UserProfile::class)
        ->set('avatarUpload', UploadedFile::fake()->create('resume.pdf', 10, 'application/pdf'))
        ->set('name', 'Ada Lovelace')
        ->set('timezone', 'Asia/Kolkata')
        ->set('locale', 'en')
        ->call('updateProfile')
        ->assertHasErrors('avatarUpload');
});

it('changes the password given the correct current password', function () {
    $student = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'password' => bcrypt('old-password'),
    ]);
    $student->assignRole('student');

    $this->actingAs($student);

    Livewire::test(UserProfile::class)
        ->set('currentPassword', 'old-password')
        ->set('newPassword', 'brand-new-password')
        ->set('newPassword_confirmation', 'brand-new-password')
        ->call('changePassword')
        ->assertHasNoErrors();

    expect(Hash::check('brand-new-password', $student->fresh()->password))->toBeTrue();
});

it('refuses to change the password with the wrong current password', function () {
    $student = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'password' => bcrypt('old-password'),
    ]);
    $student->assignRole('student');

    $this->actingAs($student);

    Livewire::test(UserProfile::class)
        ->set('currentPassword', 'wrong-password')
        ->set('newPassword', 'brand-new-password')
        ->set('newPassword_confirmation', 'brand-new-password')
        ->call('changePassword')
        ->assertHasErrors('currentPassword');

    expect(Hash::check('old-password', $student->fresh()->password))->toBeTrue();
});

it('refuses to change the password when the confirmation does not match', function () {
    $student = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'password' => bcrypt('old-password'),
    ]);
    $student->assignRole('student');

    $this->actingAs($student);

    Livewire::test(UserProfile::class)
        ->set('currentPassword', 'old-password')
        ->set('newPassword', 'brand-new-password')
        ->set('newPassword_confirmation', 'does-not-match')
        ->call('changePassword')
        ->assertHasErrors('newPassword');
});

it('does not mutate anything when a state-changing action is called with no authenticated user', function () {
    // Livewire actions are directly callable via /livewire/update
    // regardless of what the @auth-gated view shows — the guard has to be
    // in the action itself.
    Livewire::test(UserProfile::class)
        ->set('name', 'Nobody')
        ->call('updateProfile');

    expect(User::where('name', 'Nobody')->exists())->toBeFalse();
});
