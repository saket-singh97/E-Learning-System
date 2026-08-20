<?php

use App\Domains\Content\Filament\Resources\Categories\CategoryResource;
use App\Domains\Content\Filament\Resources\Courses\CourseResource;
use App\Domains\Content\Filament\Resources\CourseReviews\CourseReviewResource;
use App\Domains\Content\Filament\Resources\Lessons\LessonResource;
use App\Domains\Content\Filament\Resources\Programs\ProgramResource;
use App\Domains\Content\Filament\Resources\Tags\TagResource;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * S13 B17 — the review pass found the Teach panel's own directory-wide
 * discoverResources() on Domains/Content/Filament/Resources silently
 * exposed Category/Tag/Program/CourseReview management to any instructor,
 * even though AdminPanelProvider's own explicit ->resources() array
 * (B0/B7/B15) only ever *added* those to the Admin panel, never removed
 * them from Teach's own scan. Locks the boundary down both directions.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->instructor->assignRole('instructor');
});

it('does not register Category/Tag/Program/CourseReview resources on the Teach panel at all', function () {
    $teachResources = \Filament\Facades\Filament::getPanel('teach')->getResources();

    expect($teachResources)->not->toContain(CategoryResource::class)
        ->not->toContain(TagResource::class)
        ->not->toContain(ProgramResource::class)
        ->not->toContain(CourseReviewResource::class);
});

it('cannot even generate a Teach-panel URL for the Admin-only resources — the route no longer exists', function (string $resourceClass) {
    expect(fn () => $resourceClass::getUrl('index', ['subdomain' => 'physics'], panel: 'teach'))
        ->toThrow(\Symfony\Component\Routing\Exception\RouteNotFoundException::class);
})->with([
    'CategoryResource' => [CategoryResource::class],
    'TagResource' => [TagResource::class],
    'ProgramResource' => [ProgramResource::class],
    'CourseReviewResource' => [CourseReviewResource::class],
]);

it('still lets an instructor reach Course/Lesson resources on the Teach panel', function () {
    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Intro', 'type' => 'text', 'position' => 0]);

    $this->actingAs($this->instructor)
        ->get(CourseResource::getUrl('index', ['subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();

    $this->actingAs($this->instructor)
        ->get(LessonResource::getUrl('edit', ['record' => $lesson, 'subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();
});
