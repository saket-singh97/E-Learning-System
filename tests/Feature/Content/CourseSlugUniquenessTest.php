<?php

use App\Domains\Content\Filament\Resources\Courses\CourseResource;
use App\Domains\Content\Filament\Resources\Courses\Pages\CreateCourse;
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

it('rejects a duplicate slug within the same tenant with a form error, not a database crash', function () {
    Course::create(['title' => 'Mechanics', 'slug' => 'mechanics']);

    Livewire::test(CreateCourse::class)
        ->fillForm([
            'title' => 'Mechanics Redux',
            'slug' => 'mechanics',
            'status' => 'draft',
        ])
        ->call('create')
        ->assertHasFormErrors(['slug']);

    expect(Course::where('slug', 'mechanics')->count())->toBe(1);
});

it('lets a new course reuse the slug of a soft-deleted one through the create form', function () {
    $deleted = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics']);
    $deleted->delete();

    Livewire::test(CreateCourse::class)
        ->fillForm([
            'title' => 'Mechanics Again',
            'slug' => 'mechanics',
            'status' => 'draft',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Course::where('slug', 'mechanics')->count())->toBe(1);
});

it('allows the same slug to be reused across different tenants', function () {
    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    app(CurrentTenant::class)->set($otherTenant);
    Course::create(['title' => 'Mechanics', 'slug' => 'mechanics']);
    app(CurrentTenant::class)->set($this->tenant);

    Livewire::test(CreateCourse::class)
        ->fillForm([
            'title' => 'Mechanics',
            'slug' => 'mechanics',
            'status' => 'draft',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Course::where('slug', 'mechanics')->count())->toBe(2);
});
