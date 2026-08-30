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

/**
 * S23 B7 (U17 pt.1) — translation_of_course_id is a plain self-referencing
 * FK column, not a Filament ->relationship() Select, so a tampered payload
 * writes straight to it with no query in between. Same "->rule() is the
 * real gate" class of fix S13 B17 already applied to CourseResource's own
 * categories/tags fields (see CourseTaxonomyTenantScopingTest).
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

it('rejects a translation_of_course_id belonging to another tenant', function () {
    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    app(CurrentTenant::class)->set($otherTenant);
    $foreignCourse = Course::create(['title' => 'Foreign Physics', 'slug' => 'foreign-physics']);
    app(CurrentTenant::class)->set($this->tenant);

    Livewire::actingAs($this->instructor)
        ->test(CreateCourse::class)
        ->fillForm([
            'title' => 'Physics Hindi',
            'slug' => 'physics-hindi',
            'status' => 'draft',
            'language' => 'hi',
            'translation_of_course_id' => $foreignCourse->id,
        ])
        ->call('create')
        ->assertHasFormErrors(['translation_of_course_id']);
});

it('rejects a course being linked as a translation of itself', function () {
    $course = Course::create(['title' => 'Physics', 'slug' => 'physics']);

    Livewire::actingAs($this->instructor)
        ->test(EditCourse::class, ['record' => $course->id])
        ->fillForm(['translation_of_course_id' => $course->id])
        ->call('save')
        ->assertHasFormErrors(['translation_of_course_id']);
});

it('accepts a real, same-tenant translation link', function () {
    $english = Course::create(['title' => 'Physics', 'slug' => 'physics']);

    Livewire::actingAs($this->instructor)
        ->test(CreateCourse::class)
        ->fillForm([
            'title' => 'Physics Hindi',
            'slug' => 'physics-hindi',
            'status' => 'draft',
            'language' => 'hi',
            'translation_of_course_id' => $english->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $hindiCourse = Course::where('slug', 'physics-hindi')->sole();

    expect($hindiCourse->translation_of_course_id)->toBe($english->id)
        ->and($hindiCourse->language)->toBe('hi');
});
