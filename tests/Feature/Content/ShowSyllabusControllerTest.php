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

// S23 B8 (U17 pt.2) — design doc §4 decision 5: the switcher resolves in
// whichever direction translation_of_course_id was actually set.
it('shows a language switcher resolving from the translated course to the original', function () {
    $english = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published', 'language' => 'en']);
    $hindi = Course::create(['title' => 'Physics 101 Hindi', 'slug' => 'physics-101-hindi', 'status' => 'published', 'language' => 'hi', 'translation_of_course_id' => $english->id]);

    $this->get("http://physics.lms.test/courses/{$hindi->slug}")
        ->assertOk()
        ->assertSee('Available in')
        ->assertSee(route('courses.show', ['subdomain' => 'physics', 'course' => $english->slug]), escape: false);
});

it('shows a language switcher resolving from the original course to its translation', function () {
    $english = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published', 'language' => 'en']);
    $hindi = Course::create(['title' => 'Physics 101 Hindi', 'slug' => 'physics-101-hindi', 'status' => 'published', 'language' => 'hi', 'translation_of_course_id' => $english->id]);

    $this->get("http://physics.lms.test/courses/{$english->slug}")
        ->assertOk()
        ->assertSee('Available in')
        ->assertSee(route('courses.show', ['subdomain' => 'physics', 'course' => $hindi->slug]), escape: false);
});

it('shows no switcher when no translation link exists', function () {
    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);

    $this->get("http://physics.lms.test/courses/{$course->slug}")
        ->assertOk()
        ->assertDontSee('Available in');
});

it('hides a draft translation counterpart from a guest', function () {
    $english = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published', 'language' => 'en']);
    Course::create(['title' => 'Physics 101 Hindi', 'slug' => 'physics-101-hindi', 'status' => 'draft', 'language' => 'hi', 'translation_of_course_id' => $english->id]);

    $this->get("http://physics.lms.test/courses/{$english->slug}")
        ->assertOk()
        ->assertDontSee('Available in');
});
