<?php

use App\Domains\Content\Filament\Resources\CourseReviews\CourseReviewResource;
use App\Domains\Content\Filament\Resources\CourseReviews\Pages\ListCourseReviews;
use App\Domains\Content\Livewire\CourseReviews;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseReview;
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

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin->assignRole('institute_admin');

    $this->course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics', 'status' => 'published']);
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->review = CourseReview::create(['course_id' => $this->course->id, 'user_id' => $student->id, 'rating' => 1, 'body' => 'Terrible']);

    $this->actingAs($this->admin)
        ->get(CourseReviewResource::getUrl('index', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();
});

it('lists a review with its course, reviewer, rating and status', function () {
    Livewire::test(ListCourseReviews::class)
        ->assertSee('Mechanics')
        ->assertSee('Terrible')
        ->assertSee('published');
});

it('hides a published review, which then disappears from the public list, reversibly', function () {
    Livewire::test(ListCourseReviews::class)
        ->callTableAction('hide', $this->review);

    expect($this->review->fresh()->status)->toBe('hidden');

    Livewire::test(CourseReviews::class, ['course' => $this->course])
        ->assertDontSee('Terrible');

    Livewire::test(ListCourseReviews::class)
        ->callTableAction('show', $this->review);

    expect($this->review->fresh()->status)->toBe('published');

    Livewire::test(CourseReviews::class, ['course' => $this->course])
        ->assertSee('Terrible');
});

it('refuses a non-admin', function () {
    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');

    $this->actingAs($instructor)
        ->get(CourseReviewResource::getUrl('index', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertForbidden();
});
