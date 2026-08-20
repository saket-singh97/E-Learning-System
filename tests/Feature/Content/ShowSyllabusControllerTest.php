<?php

use App\Domains\Content\Models\Category;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);
});

it('shows same-category recommendations on the course page', function () {
    $category = Category::create(['name' => 'Science', 'slug' => 'science']);

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $course->categories()->attach($category);

    $related = Course::create(['title' => 'Chemistry 101', 'slug' => 'chemistry-101', 'status' => 'published']);
    $related->categories()->attach($category);

    $this->get("http://physics.lms.test/courses/{$course->slug}")
        ->assertOk()
        ->assertSee('You might also like')
        ->assertSee($related->title);
});

it('shows no recommendations section when nothing shares a category or tag', function () {
    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);

    $this->get("http://physics.lms.test/courses/{$course->slug}")
        ->assertOk()
        ->assertDontSee('You might also like');
});
