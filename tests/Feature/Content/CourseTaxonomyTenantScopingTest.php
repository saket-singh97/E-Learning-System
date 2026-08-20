<?php

use App\Domains\Content\Filament\Resources\Courses\CourseResource;
use App\Domains\Content\Filament\Resources\Courses\Pages\CreateCourse;
use App\Domains\Content\Models\Category;
use App\Domains\Content\Models\Tag;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * S13 B17 — CourseResource's categories/tags Select fields had a
 * ->relationship() query that only scopes the dropdown's own rendered
 * options; Filament's relationship-sync still writes whatever raw ids the
 * request actually submits, unfiltered by that query. Without a real
 * ->rule() gate, a tampered payload could attach another tenant's
 * category/tag id directly to a course.
 */
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

it('rejects a category id belonging to another tenant', function () {
    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    app(CurrentTenant::class)->set($otherTenant);
    $foreignCategory = Category::create(['name' => 'Foreign', 'slug' => 'foreign']);
    app(CurrentTenant::class)->set($this->tenant);

    Livewire::actingAs($this->instructor)
        ->test(CreateCourse::class)
        ->fillForm([
            'title' => 'Mechanics',
            'slug' => 'mechanics',
            'status' => 'draft',
            'categories' => [$foreignCategory->id],
        ])
        ->call('create')
        ->assertHasFormErrors(['categories']);
});

it('rejects a tag id belonging to another tenant', function () {
    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    app(CurrentTenant::class)->set($otherTenant);
    $foreignTag = Tag::create(['name' => 'Foreign', 'slug' => 'foreign']);
    app(CurrentTenant::class)->set($this->tenant);

    Livewire::actingAs($this->instructor)
        ->test(CreateCourse::class)
        ->fillForm([
            'title' => 'Mechanics',
            'slug' => 'mechanics',
            'status' => 'draft',
            'tags' => [$foreignTag->id],
        ])
        ->call('create')
        ->assertHasFormErrors(['tags']);
});

it('accepts a real, same-tenant category and tag', function () {
    $category = Category::create(['name' => 'Science', 'slug' => 'science']);
    $tag = Tag::create(['name' => 'Beginner', 'slug' => 'beginner']);

    Livewire::actingAs($this->instructor)
        ->test(CreateCourse::class)
        ->fillForm([
            'title' => 'Mechanics',
            'slug' => 'mechanics',
            'status' => 'draft',
            'categories' => [$category->id],
            'tags' => [$tag->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();
});
