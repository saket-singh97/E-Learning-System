<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Category;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\Tag;
use App\Domains\Content\Services\RecommendationService;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);
    $this->service = app(RecommendationService::class);
});

it('recommends published courses sharing a category, excluding the source course', function () {
    $category = Category::create(['name' => 'Science', 'slug' => 'science']);

    $source = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $source->categories()->attach($category);

    $related = Course::create(['title' => 'Chemistry 101', 'slug' => 'chemistry-101', 'status' => 'published']);
    $related->categories()->attach($category);

    $unrelated = Course::create(['title' => 'Painting 101', 'slug' => 'painting-101', 'status' => 'published']);

    $recommendations = $this->service->forCourse($source, null);

    expect($recommendations->pluck('id'))
        ->toContain($related->id)
        ->not->toContain($source->id)
        ->not->toContain($unrelated->id);
});

it('recommends published courses sharing a tag', function () {
    $tag = Tag::create(['name' => 'Beginner', 'slug' => 'beginner']);

    $source = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $source->tags()->attach($tag);

    $related = Course::create(['title' => 'Chemistry 101', 'slug' => 'chemistry-101', 'status' => 'published']);
    $related->tags()->attach($tag);

    expect($this->service->forCourse($source, null)->pluck('id'))->toContain($related->id);
});

it('excludes draft courses from recommendations', function () {
    $category = Category::create(['name' => 'Science', 'slug' => 'science']);

    $source = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $source->categories()->attach($category);

    $draft = Course::create(['title' => 'Draft Course', 'slug' => 'draft-course', 'status' => 'draft']);
    $draft->categories()->attach($category);

    expect($this->service->forCourse($source, null)->pluck('id'))->not->toContain($draft->id);
});

it('excludes a course the viewer is already enrolled in from forCourse recommendations', function () {
    $category = Category::create(['name' => 'Science', 'slug' => 'science']);
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $source = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $source->categories()->attach($category);

    $alreadyEnrolled = Course::create(['title' => 'Chemistry 101', 'slug' => 'chemistry-101', 'status' => 'published']);
    $alreadyEnrolled->categories()->attach($category);
    $batch = Batch::create([
        'course_id' => $alreadyEnrolled->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    Enrolment::create([
        'batch_id' => $batch->id,
        'user_id' => $student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    expect($this->service->forCourse($source, $student)->pluck('id'))->not->toContain($alreadyEnrolled->id);
});

it('returns nothing for forViewer when the viewer has no enrolments', function () {
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);

    expect($this->service->forViewer($student))->toBeEmpty();
    expect($this->service->forViewer(null))->toBeEmpty();
});

it('recommends based on categories of courses the viewer is enrolled in', function () {
    $category = Category::create(['name' => 'Science', 'slug' => 'science']);
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $enrolledCourse = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $enrolledCourse->categories()->attach($category);
    $batch = Batch::create([
        'course_id' => $enrolledCourse->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    Enrolment::create([
        'batch_id' => $batch->id,
        'user_id' => $student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    $related = Course::create(['title' => 'Chemistry 101', 'slug' => 'chemistry-101', 'status' => 'published']);
    $related->categories()->attach($category);

    $recommendations = $this->service->forViewer($student);

    expect($recommendations->pluck('id'))
        ->toContain($related->id)
        ->not->toContain($enrolledCourse->id);
});
