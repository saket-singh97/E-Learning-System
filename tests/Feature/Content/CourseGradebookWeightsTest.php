<?php

use App\Domains\Content\Filament\Resources\Courses\CourseResource;
use App\Domains\Content\Filament\Resources\Courses\Pages\CreateCourse;
use App\Domains\Content\Filament\Resources\Courses\Pages\EditCourse;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->instructor->assignRole('instructor');

    $this->actingAs($this->instructor)
        ->get(CourseResource::getUrl('create', ['subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();
});

it('defaults an existing course to the B0 migration weights with no admin action needed', function () {
    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics']);

    expect($course->fresh()->gradebook_weights)->toBe(['quiz' => 50, 'assignment' => 50])
        ->and((float) $course->fresh()->passing_threshold)->toBe(40.0);
});

it('creates a course with a custom weight split that sums to 100', function () {
    Livewire::test(CreateCourse::class)
        ->fillForm([
            'title' => 'Applied Mechanics',
            'slug' => 'applied-mechanics',
            'gradebook_weights' => ['quiz' => 30, 'assignment' => 70],
            'passing_threshold' => 35,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $course = Course::sole();

    expect($course->gradebook_weights)->toBe(['quiz' => 30, 'assignment' => 70])
        ->and((float) $course->passing_threshold)->toBe(35.0);
});

it('rejects a weight split that does not sum to 100', function () {
    Livewire::test(CreateCourse::class)
        ->fillForm([
            'title' => 'Bad Weights',
            'slug' => 'bad-weights',
            'gradebook_weights' => ['quiz' => 30, 'assignment' => 30],
            'passing_threshold' => 40,
        ])
        ->call('create')
        ->assertHasFormErrors(['gradebook_weights.assignment']);

    expect(Course::count())->toBe(0);
});

it('lets an admin edit an existing course\'s weights', function () {
    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics']);

    Livewire::test(EditCourse::class, ['record' => $course->id])
        ->fillForm([
            'gradebook_weights' => ['quiz' => 60, 'assignment' => 40],
            'passing_threshold' => 50,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $course->refresh();

    expect($course->gradebook_weights)->toBe(['quiz' => 60, 'assignment' => 40])
        ->and((float) $course->passing_threshold)->toBe(50.0);
});
