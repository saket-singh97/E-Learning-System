<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
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

function courseReviewBatch(Course $course, string $slug): Batch
{
    return Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch',
        'slug' => $slug,
        'starts_on' => now()->subMonths(2)->toDateString(),
        'ends_on' => now()->subMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics', 'status' => 'published']);
    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

it('lets an enrolled student submit a review', function () {
    $batch = courseReviewBatch($this->course, 'batch-1');
    Enrolment::create(['batch_id' => $batch->id, 'user_id' => $this->student->id, 'status' => 'active', 'source' => 'manual_add', 'requested_at' => now()]);

    Livewire::actingAs($this->student)
        ->test(CourseReviews::class, ['course' => $this->course])
        ->set('rating', 4)
        ->set('body', 'Learned a lot')
        ->call('submit')
        ->assertSee('Learned a lot');

    $review = CourseReview::where('course_id', $this->course->id)->where('user_id', $this->student->id)->sole();
    expect($review->rating)->toBe(4)
        ->and($review->body)->toBe('Learned a lot')
        ->and($review->status)->toBe('published');
});

it('refuses a review from a student never enrolled in the course', function () {
    Livewire::actingAs($this->student)
        ->test(CourseReviews::class, ['course' => $this->course])
        ->set('rating', 5)
        ->call('submit');

    expect(CourseReview::count())->toBe(0);
});

it('no-ops for a guest', function () {
    Livewire::test(CourseReviews::class, ['course' => $this->course])
        ->set('rating', 5)
        ->call('submit');

    expect(CourseReview::count())->toBe(0);
});

it('updates the existing review instead of creating a duplicate on re-submission', function () {
    $batch = courseReviewBatch($this->course, 'batch-1');
    Enrolment::create(['batch_id' => $batch->id, 'user_id' => $this->student->id, 'status' => 'active', 'source' => 'manual_add', 'requested_at' => now()]);

    Livewire::actingAs($this->student)
        ->test(CourseReviews::class, ['course' => $this->course])
        ->set('rating', 3)
        ->call('submit');

    Livewire::actingAs($this->student)
        ->test(CourseReviews::class, ['course' => $this->course])
        ->set('rating', 5)
        ->set('body', 'Changed my mind, great course')
        ->call('submit');

    expect(CourseReview::where('course_id', $this->course->id)->where('user_id', $this->student->id)->count())->toBe(1);
    $review = CourseReview::where('course_id', $this->course->id)->where('user_id', $this->student->id)->sole();
    expect($review->rating)->toBe(5);
});

it('allows a review from a past enrolment, not scoped to a current batch', function () {
    $pastBatch = courseReviewBatch($this->course, 'past-batch');
    Enrolment::create(['batch_id' => $pastBatch->id, 'user_id' => $this->student->id, 'status' => 'expired', 'source' => 'manual_add', 'requested_at' => now()->subMonths(3)]);

    Livewire::actingAs($this->student)
        ->test(CourseReviews::class, ['course' => $this->course])
        ->set('rating', 4)
        ->call('submit');

    expect(CourseReview::where('course_id', $this->course->id)->where('user_id', $this->student->id)->exists())->toBeTrue();
});

/**
 * S13 B17 — the original CourseReviewPolicy::canReview() counted any
 * Enrolment status at all, letting a rejected or never-paid-for
 * applicant post a public review. Only genuine participation
 * (active/expired) should qualify.
 */
it('refuses a review from a rejected enrolment', function () {
    $batch = courseReviewBatch($this->course, 'batch-1');
    Enrolment::create(['batch_id' => $batch->id, 'user_id' => $this->student->id, 'status' => 'rejected', 'source' => 'manual_add', 'requested_at' => now()]);

    Livewire::actingAs($this->student)
        ->test(CourseReviews::class, ['course' => $this->course])
        ->set('rating', 1)
        ->call('submit');

    expect(CourseReview::count())->toBe(0);
});

it('refuses a review from a never-completed pending_payment enrolment', function () {
    $batch = courseReviewBatch($this->course, 'batch-1');
    Enrolment::create(['batch_id' => $batch->id, 'user_id' => $this->student->id, 'status' => 'pending_payment', 'source' => 'self_enrol_direct', 'requested_at' => now()]);

    Livewire::actingAs($this->student)
        ->test(CourseReviews::class, ['course' => $this->course])
        ->set('rating', 5)
        ->call('submit');

    expect(CourseReview::count())->toBe(0);
});

it('rejects a rating outside 1-5', function () {
    $batch = courseReviewBatch($this->course, 'batch-1');
    Enrolment::create(['batch_id' => $batch->id, 'user_id' => $this->student->id, 'status' => 'active', 'source' => 'manual_add', 'requested_at' => now()]);

    Livewire::actingAs($this->student)
        ->test(CourseReviews::class, ['course' => $this->course])
        ->set('rating', 6)
        ->call('submit')
        ->assertHasErrors(['rating']);

    expect(CourseReview::count())->toBe(0);
});

it('lets the tenant own instructor respond to a review', function () {
    $review = CourseReview::create(['course_id' => $this->course->id, 'user_id' => $this->student->id, 'rating' => 4, 'body' => 'Good course']);

    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');

    Livewire::actingAs($instructor)
        ->test(CourseReviews::class, ['course' => $this->course])
        ->set("responseDrafts.{$review->id}", 'Thanks for the feedback!')
        ->call('respond', $review->id)
        ->assertSee('Thanks for the feedback!');

    $review->refresh();
    expect($review->instructor_response)->toBe('Thanks for the feedback!')
        ->and($review->instructor_response_at)->not->toBeNull();
});

it('does not let a student respond to a review', function () {
    $review = CourseReview::create(['course_id' => $this->course->id, 'user_id' => $this->student->id, 'rating' => 4]);

    Livewire::actingAs($this->student)
        ->test(CourseReviews::class, ['course' => $this->course])
        ->set("responseDrafts.{$review->id}", 'Thanks!')
        ->call('respond', $review->id);

    expect($review->fresh()->instructor_response)->toBeNull();
});

it('does not let a guest respond to a review', function () {
    $review = CourseReview::create(['course_id' => $this->course->id, 'user_id' => $this->student->id, 'rating' => 4]);

    Livewire::test(CourseReviews::class, ['course' => $this->course])
        ->set("responseDrafts.{$review->id}", 'Thanks!')
        ->call('respond', $review->id);

    expect($review->fresh()->instructor_response)->toBeNull();
});
