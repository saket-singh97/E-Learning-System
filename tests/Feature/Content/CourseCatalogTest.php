<?php

use App\Domains\Content\Models\Category;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\Tag;
use App\Domains\Content\Models\Wishlist;
use App\Domains\Tenancy\Models\Tenant;
use App\Livewire\CourseCatalog;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);
});

it('lists only published courses', function () {
    $published = Course::create(['title' => 'Published Course', 'slug' => 'published-course', 'status' => 'published']);
    Course::create(['title' => 'Draft Course', 'slug' => 'draft-course', 'status' => 'draft']);

    Livewire::test(CourseCatalog::class)
        ->assertSee($published->title)
        ->assertDontSee('Draft Course');
});

it('filters by category', function () {
    $category = Category::create(['name' => 'Science', 'slug' => 'science']);
    $other = Category::create(['name' => 'Arts', 'slug' => 'arts']);

    $matching = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $matching->categories()->attach($category);

    $nonMatching = Course::create(['title' => 'Painting 101', 'slug' => 'painting-101', 'status' => 'published']);
    $nonMatching->categories()->attach($other);

    Livewire::test(CourseCatalog::class)
        ->set('categoryId', $category->id)
        ->assertSee($matching->title)
        ->assertDontSee($nonMatching->title);
});

it('filters by tag', function () {
    $tag = Tag::create(['name' => 'Beginner', 'slug' => 'beginner']);
    $other = Tag::create(['name' => 'Advanced', 'slug' => 'advanced']);

    $matching = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $matching->tags()->attach($tag);

    $nonMatching = Course::create(['title' => 'Physics 900', 'slug' => 'physics-900', 'status' => 'published']);
    $nonMatching->tags()->attach($other);

    Livewire::test(CourseCatalog::class)
        ->set('tagId', $tag->id)
        ->assertSee($matching->title)
        ->assertDontSee($nonMatching->title);
});

it('combines category and tag filters with AND', function () {
    $category = Category::create(['name' => 'Science', 'slug' => 'science']);
    $tag = Tag::create(['name' => 'Beginner', 'slug' => 'beginner']);

    $matching = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $matching->categories()->attach($category);
    $matching->tags()->attach($tag);

    $categoryOnly = Course::create(['title' => 'Physics 900', 'slug' => 'physics-900', 'status' => 'published']);
    $categoryOnly->categories()->attach($category);

    Livewire::test(CourseCatalog::class)
        ->set('categoryId', $category->id)
        ->set('tagId', $tag->id)
        ->assertSee($matching->title)
        ->assertDontSee($categoryOnly->title);
});

it('searches by title', function () {
    $matching = Course::create(['title' => 'Advanced Physics', 'slug' => 'advanced-physics', 'status' => 'published']);
    $nonMatching = Course::create(['title' => 'Basic Chemistry', 'slug' => 'basic-chemistry', 'status' => 'published']);

    Livewire::test(CourseCatalog::class)
        ->set('search', 'Physics')
        ->assertSee($matching->title)
        ->assertDontSee($nonMatching->title);
});

it('searches by description', function () {
    $matching = Course::create(['title' => 'Course A', 'slug' => 'course-a', 'status' => 'published', 'description' => 'Learn quantum mechanics here']);
    $nonMatching = Course::create(['title' => 'Course B', 'slug' => 'course-b', 'status' => 'published', 'description' => 'Learn painting here']);

    Livewire::test(CourseCatalog::class)
        ->set('search', 'quantum')
        ->assertSee($matching->title)
        ->assertDontSee($nonMatching->title);
});

it('returns no results for a search term matching nothing', function () {
    Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);

    Livewire::test(CourseCatalog::class)
        ->set('search', 'Nonexistent Term')
        ->assertSee('No courses match these filters.');
});

it('toggles a course onto and off an authenticated user wishlist', function () {
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);

    Livewire::actingAs($student)
        ->test(CourseCatalog::class)
        ->assertSee('Add to wishlist')
        ->call('toggleWishlist', $course->id)
        ->assertSee('Remove from wishlist');

    expect(Wishlist::where('user_id', $student->id)->where('course_id', $course->id)->exists())->toBeTrue();

    Livewire::actingAs($student)
        ->test(CourseCatalog::class)
        ->call('toggleWishlist', $course->id);

    expect(Wishlist::where('user_id', $student->id)->where('course_id', $course->id)->exists())->toBeFalse();
});

it('no-ops the wishlist toggle for a guest', function () {
    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);

    Livewire::test(CourseCatalog::class)
        ->call('toggleWishlist', $course->id);

    expect(Wishlist::where('course_id', $course->id)->exists())->toBeFalse();
});

it('shows recommendations based on the viewer own enrolments, none for a guest', function () {
    $category = Category::create(['name' => 'Science', 'slug' => 'science']);
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $enrolledCourse = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $enrolledCourse->categories()->attach($category);
    $batch = \App\Domains\Batches\Models\Batch::create([
        'course_id' => $enrolledCourse->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    \App\Domains\Batches\Models\Enrolment::create([
        'batch_id' => $batch->id,
        'user_id' => $student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    $related = Course::create(['title' => 'Chemistry 101', 'slug' => 'chemistry-101', 'status' => 'published']);
    $related->categories()->attach($category);

    Livewire::test(CourseCatalog::class)
        ->assertDontSee('Recommended for you');

    Livewire::actingAs($student)
        ->test(CourseCatalog::class)
        ->assertSee('Recommended for you')
        ->assertSee($related->title);
});
