<?php

use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    // Not bound via app(CurrentTenant::class)->set() here on purpose —
    // the real ResolveTenant middleware binds it per-request from each
    // test's own subdomain host, and the super-panel test below hits the
    // bare lms.test domain with no tenant at all; pre-binding here would
    // leak a stale tenant into that request and trip EnsureRole's
    // cross-tenant check (found while writing this test).
    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
});

it('renders the notification centre on the student dashboard', function () {
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $student->assignRole('student');

    $this->actingAs($student)
        ->get('http://physics.lms.test/dashboard')
        ->assertOk()
        ->assertSee('Notifications');
});

it('renders the notification centre on the parent dashboard', function () {
    $parent = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $parent->assignRole('parent');

    $this->actingAs($parent)
        ->get('http://physics.lms.test/parent/dashboard')
        ->assertOk()
        ->assertSee('Notifications');
});

it('does not render a cross-tenant parents notifications before the tenant-mismatch logout takes effect', function () {
    // Regression for S8 B8's review finding: notification-centre used to
    // mount before parent-portal in the layout, so it briefly read
    // Auth::user() (SESSION_DOMAIN keeps the cookie valid) before
    // ParentPortal::mount()'s own tenant-mismatch logout ran.
    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);

    $parent = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $parent->assignRole('parent');

    DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\SomeNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $parent->id,
        'data' => ['foo' => 'bar'],
        'read_at' => null,
    ]);

    $this->actingAs($parent)
        ->get('http://chemistry.lms.test/parent/dashboard')
        ->assertOk()
        ->assertDontSee('unread');

    $this->assertGuest();
});

it('renders the notification centre on the admin panel', function () {
    $admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $admin->assignRole('institute_admin');

    $this->actingAs($admin)
        ->get('http://physics.lms.test/admin')
        ->assertOk()
        ->assertSee('Notifications');
});

it('renders the notification centre on the teach panel', function () {
    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');

    $this->actingAs($instructor)
        ->get('http://physics.lms.test/teach')
        ->assertOk()
        ->assertSee('Notifications');
});

it('renders the notification centre on the super panel', function () {
    $superAdmin = User::factory()->create(['tenant_id' => null]);
    $superAdmin->assignRole('super_admin');

    $this->actingAs($superAdmin)
        ->get('http://lms.test/super')
        ->assertOk()
        ->assertSee('Notifications');
});
