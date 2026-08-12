<?php

use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

function makeLesson(Tenant $tenant, array $courseAttrs = [], array $lessonAttrs = []): Lesson
{
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(array_merge(['title' => 'Mechanics', 'slug' => 'mechanics', 'status' => 'published'], $courseAttrs));
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create(array_merge([
        'course_section_id' => $section->id,
        'title' => 'Velocity',
        'type' => 'video',
        'position' => 0,
        'video_status' => 'ready',
    ], $lessonAttrs));

    $lesson->update(['video_disk_path' => "videos/{$tenant->id}/{$lesson->id}.mp4"]);
    Storage::disk('local')->put($lesson->video_disk_path, 'fake video bytes');

    return $lesson->fresh();
}

function signedWatchUrl(Lesson $lesson, string $subdomain): string
{
    return URL::temporarySignedRoute('watch.stream', now()->addMinutes(10), [
        'subdomain' => $subdomain,
        'lesson' => $lesson->id,
    ]);
}

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RoleSeeder::class);
});

it('lets a student in the right tenant stream a published, non-preview lesson', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $lesson = makeLesson($tenant);

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    $this->actingAs($student)
        ->get(signedWatchUrl($lesson, 'physics'))
        ->assertOk();
});

it('404s the same signed url opened under a different tenant session', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $lesson = makeLesson($tenant);

    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    app(CurrentTenant::class)->set($otherTenant);
    $otherStudent = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherStudent->assignRole('student');

    $this->actingAs($otherStudent)
        ->get(signedWatchUrl($lesson, 'chemistry'))
        ->assertNotFound();
});

it('403s a student authenticated as a different tenant even though the lesson resolves under the right subdomain', function () {
    // Distinct from the 404 test above: here the request goes to the
    // lesson's OWN subdomain (so Eloquent's tenant scope resolves it fine),
    // but the shared SESSION_DOMAIN cookie has kept a different tenant's
    // student authenticated. canStream() must reject on the viewer's own
    // tenant_id, not just on whether the lesson row itself was findable.
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $lesson = makeLesson($tenant);

    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    $otherStudent = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherStudent->assignRole('student');

    $this->actingAs($otherStudent)
        ->get(signedWatchUrl($lesson, 'physics'))
        ->assertForbidden();
});

it('lets a guest stream a free-preview lesson with no login', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $lesson = makeLesson($tenant, [], ['is_free_preview' => true]);

    $this->get(signedWatchUrl($lesson, 'physics'))
        ->assertOk();
});

it('lets an instructor preview their own draft-course lesson', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $lesson = makeLesson($tenant, ['status' => 'draft']);

    $instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $instructor->assignRole('instructor');

    $this->actingAs($instructor)
        ->get(signedWatchUrl($lesson, 'physics'))
        ->assertOk();
});

it('404s a student hitting that same draft-course lesson', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $lesson = makeLesson($tenant, ['status' => 'draft']);

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    $this->actingAs($student)
        ->get(signedWatchUrl($lesson, 'physics'))
        ->assertNotFound();
});

it('403s a guest on a non-preview lesson', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $lesson = makeLesson($tenant);

    $this->get(signedWatchUrl($lesson, 'physics'))
        ->assertForbidden();
});
