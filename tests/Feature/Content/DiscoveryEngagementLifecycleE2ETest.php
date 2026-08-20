<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Services\BatchEnrolmentService;
use App\Domains\Content\Models\Category;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Domains\Content\Models\Wishlist;
use App\Domains\Content\Services\LessonAccess;
use App\Domains\Content\Services\PrerequisiteGraph;
use App\Domains\Tenancy\Models\Tenant;
use App\Livewire\CourseCatalog;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * S13 B17 — the one thing no per-block test could cover: catalog search
 * (B2), wishlist (B3), enrolment (Batches, pre-S13), and prerequisite-gated
 * unlocking (B5/B6) chained in one real run, same "individual pieces are
 * all unit-tested, but nothing proves they still fit together" posture
 * S11 B16/S12 B14's own lifecycle E2E tests already established for this
 * app.
 */
it('runs the full catalog-search -> wishlist -> enrol -> prerequisite-unlock chain', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);
    $this->seed(RoleSeeder::class);

    $category = Category::create(['name' => 'Science', 'slug' => 'science']);

    $course = Course::create(['title' => 'Advanced Physics', 'slug' => 'advanced-physics', 'status' => 'published']);
    $course->categories()->attach($category);

    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $introLesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Intro', 'type' => 'video', 'position' => 0, 'video_status' => 'ready', 'video_duration_seconds' => 100]);
    $nextLesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Next up', 'type' => 'text', 'position' => 1, 'text_body' => 'Advanced material']);
    app(PrerequisiteGraph::class)->addPrerequisite($nextLesson, $introLesson);

    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-batch',
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'self_enrol_direct',
        'status' => 'published',
    ]);

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    // 1. Catalog search finds the course by category filter.
    Livewire::actingAs($student)
        ->test(CourseCatalog::class)
        ->set('categoryId', $category->id)
        ->assertSee('Advanced Physics');

    // 2. Wishlisted from the catalog.
    Livewire::actingAs($student)
        ->test(CourseCatalog::class)
        ->call('toggleWishlist', $course->id);

    expect(Wishlist::where('user_id', $student->id)->where('course_id', $course->id)->exists())->toBeTrue();

    // 3. Enrols into the batch — a real Enrolment now exists.
    $enrolment = app(BatchEnrolmentService::class)->enrol($student, $batch);
    expect($enrolment->status)->toBe('active');

    $access = app(LessonAccess::class);

    // 4. Prerequisite-gated: the next lesson is denied before the intro
    //    lesson is watched, even though the student is genuinely enrolled.
    expect($access->canStream($introLesson, $student))->toBeTrue();
    expect($access->canStream($nextLesson, $student))->toBeFalse();

    // 5. Watching the intro lesson past the completion threshold unlocks
    //    the next one.
    VideoWatchEvent::create([
        'lesson_id' => $introLesson->id,
        'user_id' => $student->id,
        'start_s' => 0,
        'end_s' => 95,
    ]);

    expect($access->canStream($nextLesson, $student))->toBeTrue();
});
