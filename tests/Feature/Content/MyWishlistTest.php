<?php

use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\Wishlist;
use App\Domains\Tenancy\Models\Tenant;
use App\Livewire\MyWishlist;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);
});

it('lists only the authenticated user own wishlisted courses', function () {
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $otherStudent = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $mine = Course::create(['title' => 'My Course', 'slug' => 'my-course', 'status' => 'published']);
    $theirs = Course::create(['title' => 'Their Course', 'slug' => 'their-course', 'status' => 'published']);

    Wishlist::create(['user_id' => $student->id, 'course_id' => $mine->id]);
    Wishlist::create(['user_id' => $otherStudent->id, 'course_id' => $theirs->id]);

    Livewire::actingAs($student)
        ->test(MyWishlist::class)
        ->assertSee($mine->title)
        ->assertDontSee($theirs->title);
});

it('removes a course from the wishlist', function () {
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $course = Course::create(['title' => 'My Course', 'slug' => 'my-course', 'status' => 'published']);
    Wishlist::create(['user_id' => $student->id, 'course_id' => $course->id]);

    Livewire::actingAs($student)
        ->test(MyWishlist::class)
        ->assertSee($course->title)
        ->call('toggleWishlist', $course->id)
        ->assertSee('Your wishlist is empty.');

    expect(Wishlist::where('user_id', $student->id)->where('course_id', $course->id)->exists())->toBeFalse();
});

it('prompts a guest to log in instead of showing a wishlist', function () {
    $this->get('http://physics.lms.test/wishlist')
        ->assertOk()
        ->assertSee('Log in to see your wishlist.');
});

it('no-ops the wishlist toggle for a guest', function () {
    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);

    Livewire::test(MyWishlist::class)
        ->call('toggleWishlist', $course->id);

    expect(Wishlist::where('course_id', $course->id)->exists())->toBeFalse();
});
