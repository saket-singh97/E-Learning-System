<?php

use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(CurrentTenant::class)->set(Tenant::factory()->create(['subdomain' => 'physics']));
});

it('defaults a new course to draft', function () {
    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics']);

    expect($course->fresh()->status)->toBe('draft');
});

it('can be set to published or archived', function () {
    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics', 'status' => 'published']);

    expect($course->fresh()->status)->toBe('published');

    $course->update(['status' => 'archived']);

    expect($course->fresh()->status)->toBe('archived');
});
