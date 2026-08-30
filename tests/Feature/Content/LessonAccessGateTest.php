<?php

use App\Domains\Content\Filament\Resources\Lessons\LessonResource;
use App\Domains\Content\Filament\Resources\Lessons\Pages\EditLesson;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Regressions from the post-S2 review. Each test here maps to a real defect
 * found by probing paths the per-block tests never touched.
 */
function gateFixture(array $courseAttrs = [], array $lessonAttrs = [], bool $withFile = true): array
{
    test()->seed(RoleSeeder::class);

    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(array_merge(
        ['title' => 'Mechanics', 'slug' => 'mechanics', 'status' => 'published'],
        $courseAttrs,
    ));
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create(array_merge([
        'course_section_id' => $section->id,
        'title' => 'SECRET DRAFT TITLE',
        'type' => 'video',
        'position' => 0,
        'video_status' => 'ready',
    ], $lessonAttrs));

    if ($withFile) {
        $lesson->update(['video_disk_path' => "videos/{$tenant->id}/{$lesson->id}.mp4"]);
        Storage::disk('local')->put($lesson->video_disk_path, 'fake video bytes');
    }

    return [$tenant, $section, $lesson->fresh()];
}

function gateStaff(Tenant $tenant, string $role = 'instructor'): User
{
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role);

    return $user;
}

function gateSignedStreamUrl(Lesson $lesson): string
{
    return URL::temporarySignedRoute('watch.stream', now()->addMinutes(10), [
        'subdomain' => 'physics',
        'lesson' => $lesson->id,
    ]);
}

beforeEach(fn () => Storage::fake('local'));

it('hides a draft courses lesson page from a guest instead of leaking its title', function () {
    [, , $lesson] = gateFixture(['status' => 'draft']);

    $this->get("http://physics.lms.test/lessons/{$lesson->id}")
        ->assertNotFound()
        ->assertDontSee('SECRET DRAFT TITLE');
});

it('hides a future-scheduled lesson page from a student', function () {
    [$tenant, , $lesson] = gateFixture([], ['scheduled_at' => now()->addDay()]);
    $student = gateStaff($tenant, 'student');

    $this->actingAs($student)
        ->get("http://physics.lms.test/lessons/{$lesson->id}")
        ->assertNotFound();
});

it('403s a guest on the player page of a non-preview lesson', function () {
    [, , $lesson] = gateFixture();

    $this->get("http://physics.lms.test/lessons/{$lesson->id}")->assertForbidden();
});

it('lets a guest open the player page of a free-preview lesson', function () {
    [, , $lesson] = gateFixture([], ['is_free_preview' => true]);

    $this->get("http://physics.lms.test/lessons/{$lesson->id}")->assertOk();
});

// S23 B10 (U13 pt.1) — same unconditional-guest bypass as free-preview
// above, distinct flag/purpose (a demo lesson also feeds B11's conversion
// tracking).
it('lets a guest open the player page of a demo lesson', function () {
    [, , $lesson] = gateFixture([], ['is_demo' => true]);

    $this->get("http://physics.lms.test/lessons/{$lesson->id}")->assertOk();
});

it('lets a guest open a demo lesson even when its own course is still a draft', function () {
    [, , $lesson] = gateFixture(['status' => 'draft'], ['is_demo' => true]);

    $this->get("http://physics.lms.test/lessons/{$lesson->id}")->assertOk();
});

it('still requires enrolment for a non-demo lesson in the same course as a demo lesson', function () {
    [, $section, $demoLesson] = gateFixture([], ['is_demo' => true]);
    $regularLesson = Lesson::create([
        'course_section_id' => $section->id,
        'title' => 'Regular Lesson',
        'type' => 'text',
        'position' => 1,
        'text_body' => 'Hello',
    ]);

    $this->get("http://physics.lms.test/lessons/{$demoLesson->id}")->assertOk();
    $this->get("http://physics.lms.test/lessons/{$regularLesson->id}")->assertForbidden();
});

it('lets an instructor open their own draft-course lesson page', function () {
    [$tenant, , $lesson] = gateFixture(['status' => 'draft']);
    $instructor = gateStaff($tenant);

    $this->actingAs($instructor)
        ->get("http://physics.lms.test/lessons/{$lesson->id}")
        ->assertOk();
});

it('404s rather than 500s when a lesson has no stored video file', function () {
    [$tenant, , $lesson] = gateFixture([], ['video_status' => 'failed'], withFile: false);
    $instructor = gateStaff($tenant);

    $response = $this->actingAs($instructor)->get(gateSignedStreamUrl($lesson));

    $response->assertNotFound();
    expect($response->exception)->not->toBeInstanceOf(TypeError::class);
});

it('404s a stream whose row claims ready but has no disk path', function () {
    [$tenant, , $lesson] = gateFixture([], [], withFile: false);
    $student = gateStaff($tenant, 'student');

    $this->actingAs($student)->get(gateSignedStreamUrl($lesson))->assertNotFound();
});

it('refuses a heartbeat for a lesson the viewer cannot watch', function () {
    [$tenant, , $lesson] = gateFixture(['status' => 'draft']);
    $student = gateStaff($tenant, 'student');

    $url = URL::route('watch.heartbeat', ['subdomain' => 'physics', 'lesson' => $lesson->id]);

    $this->actingAs($student)
        ->postJson($url, ['start_s' => 0, 'end_s' => 10])
        ->assertNotFound();

    expect(VideoWatchEvent::count())->toBe(0);
});

it('does not expose a standalone lesson create page that cannot supply a section', function () {
    expect(array_keys(LessonResource::getPages()))->not->toContain('create');

    [$tenant] = gateFixture();

    $this->actingAs(gateStaff($tenant))
        ->get('http://physics.lms.test/teach/lessons/create')
        ->assertNotFound();
});

it('attaches a video uploaded from the standalone lesson edit page', function () {
    [$tenant, , $lesson] = gateFixture([], ['video_status' => null], withFile: false);
    $instructor = gateStaff($tenant);

    $this->actingAs($instructor)
        ->get("http://physics.lms.test/teach/lessons/{$lesson->id}/edit")
        ->assertOk();

    Livewire::test(EditLesson::class, ['record' => $lesson->id])
        ->fillForm([
            'title' => 'Velocity',
            'type' => 'video',
            'video_upload' => UploadedFile::fake()->create('lecture.mp4', 10, 'video/mp4'),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $lesson->refresh();
    expect($lesson->video_status)->toBe('ready');
    expect($lesson->video_disk_path)->toBe("videos/{$tenant->id}/{$lesson->id}.mp4");
    Storage::disk('local')->assertExists($lesson->video_disk_path);
});

it('404s a lesson whose section was soft-deleted, for students and staff alike', function () {
    [$tenant, $section, $lesson] = gateFixture();
    $section->delete();

    $this->actingAs(gateStaff($tenant, 'student'))
        ->get("http://physics.lms.test/lessons/{$lesson->id}")
        ->assertNotFound();

    $this->actingAs(gateStaff($tenant))
        ->get(gateSignedStreamUrl($lesson))
        ->assertNotFound();
});

it('403s a student authenticated as a different tenant even though the lesson itself resolves fine', function () {
    // SESSION_DOMAIN is a shared parent domain, so a student logged in at
    // one tenant stays authenticated when they open another tenant's own
    // lesson URL. Spatie roles carry no tenant_id, so canStream()'s old
    // role-only check passed regardless of which tenant the viewer actually
    // belongs to (same class of gap EnsureRole closes for panel routes).
    [$tenant, , $lesson] = gateFixture();

    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    $otherStudent = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherStudent->assignRole('student');

    $this->actingAs($otherStudent)
        ->get("http://physics.lms.test/lessons/{$lesson->id}")
        ->assertForbidden();
});

it('404s a lesson whose course was soft-deleted', function () {
    [$tenant, $section, $lesson] = gateFixture();
    $section->course->delete();

    $this->actingAs(gateStaff($tenant, 'student'))
        ->get("http://physics.lms.test/lessons/{$lesson->id}")
        ->assertNotFound();
});
