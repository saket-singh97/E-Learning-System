<?php

use App\Domains\Content\Models\Course;
use App\Domains\Growth\Filament\Resources\CourseInterestSignups\CourseInterestSignupResource;
use App\Domains\Growth\Livewire\CourseInterestForm;
use App\Domains\Growth\Models\CourseInterestSignup;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lets a guest open the interest-capture page for a draft course and sign up', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);
    $course = Course::create(['title' => 'Advanced Mechanics', 'slug' => 'advanced-mechanics', 'status' => 'draft']);

    test()->get("http://physics.lms.test/courses/advanced-mechanics/interest")
        ->assertOk()
        ->assertSee('Advanced Mechanics');

    Livewire::test(CourseInterestForm::class, ['courseId' => $course->id])
        ->set('name', 'Guest Prospect')
        ->set('email', 'guest@example.com')
        ->call('submit')
        ->assertHasNoErrors();

    expect(CourseInterestSignup::sole()->email)->toBe('guest@example.com');
});

it('never lets one tenants course interest leads leak into another tenants own list', function () {
    test()->seed(RoleSeeder::class);

    $tenantA = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenantA);
    $courseA = Course::create(['title' => 'Physics Course', 'slug' => 'physics-course', 'status' => 'draft']);
    CourseInterestSignup::create(['course_id' => $courseA->id, 'name' => 'Ours', 'email' => 'ours@example.com']);
    $adminA = User::factory()->create(['tenant_id' => $tenantA->id]);
    $adminA->assignRole('institute_admin');

    $tenantB = Tenant::factory()->create(['subdomain' => 'chemistry']);
    app(CurrentTenant::class)->set($tenantB);
    $courseB = Course::create(['title' => 'Chemistry Course', 'slug' => 'chemistry-course', 'status' => 'draft']);
    CourseInterestSignup::create(['course_id' => $courseB->id, 'name' => 'Theirs', 'email' => 'theirs@example.com']);

    app(CurrentTenant::class)->set($tenantA);

    test()->actingAs($adminA)
        ->get(CourseInterestSignupResource::getUrl('index', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk()
        ->assertSee('ours@example.com')
        ->assertDontSee('theirs@example.com');
});
