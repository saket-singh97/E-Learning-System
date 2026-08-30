<?php

use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
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

// S23 B11 (U13 pt.2) — the watch-demo action is recorded through this same
// component (design doc §4 decision 3), not a separate anonymous page.
it('records demo_attended_at and redirects to the demo lesson when a prospect watches it', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);
    $course = Course::create(['title' => 'Advanced Mechanics', 'slug' => 'advanced-mechanics', 'status' => 'draft']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);
    $demoLesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Demo', 'type' => 'text', 'text_body' => 'Hello', 'position' => 0, 'is_demo' => true]);

    $component = Livewire::test(CourseInterestForm::class, ['courseId' => $course->id])
        ->set('name', 'Guest Prospect')
        ->set('email', 'guest@example.com')
        ->call('submit')
        ->assertSee('Watch a free demo class');

    $component->call('watchDemo')
        ->assertRedirect("http://physics.lms.test/lessons/{$demoLesson->id}");

    expect(CourseInterestSignup::sole()->demo_attended_at)->not->toBeNull();
});

it('shows no demo-watch link when the course has no demo lesson', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);
    $course = Course::create(['title' => 'Advanced Mechanics', 'slug' => 'advanced-mechanics', 'status' => 'draft']);

    Livewire::test(CourseInterestForm::class, ['courseId' => $course->id])
        ->set('name', 'Guest Prospect')
        ->set('email', 'guest@example.com')
        ->call('submit')
        ->assertDontSee('Watch a free demo class');
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
