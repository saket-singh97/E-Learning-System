<?php

use App\Domains\Content\Filament\Resources\Lessons\Pages\EditLesson;
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
use Livewire\Livewire;

uses(RefreshDatabase::class);

function subtitleEditFixture(array $lessonAttrs = []): array
{
    test()->seed(RoleSeeder::class);

    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics', 'status' => 'published']);
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

    $instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $instructor->assignRole('instructor');

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    return [$tenant, $lesson->fresh(), $instructor, $student];
}

beforeEach(fn () => Storage::fake('local'));

it('hydrates the subtitle textarea from the existing VTT file on edit', function () {
    [, $lesson, $instructor] = subtitleEditFixture();

    $vtt = "WEBVTT\n\n00:00:00.000 --> 00:00:05.000\nAuto-generated cue.";
    Storage::disk('local')->put("subtitles/{$lesson->tenant_id}/{$lesson->id}.vtt", $vtt);
    $lesson->update(['subtitle_disk_path' => "subtitles/{$lesson->tenant_id}/{$lesson->id}.vtt", 'subtitle_status' => 'ready']);

    $this->actingAs($instructor)
        ->get("http://physics.lms.test/teach/lessons/{$lesson->id}/edit")
        ->assertOk();

    Livewire::test(EditLesson::class, ['record' => $lesson->id])
        ->assertFormSet(['subtitle_text' => $vtt]);
});

it('lets an instructor edit subtitle text and overwrites the stored VTT file', function () {
    [$tenant, $lesson, $instructor] = subtitleEditFixture();

    $edited = "WEBVTT\n\n00:00:00.000 --> 00:00:05.000\nCorrected wording.";

    $this->actingAs($instructor)
        ->get("http://physics.lms.test/teach/lessons/{$lesson->id}/edit")
        ->assertOk();

    Livewire::test(EditLesson::class, ['record' => $lesson->id])
        ->fillForm([
            'title' => 'Velocity',
            'type' => 'video',
            'subtitle_text' => $edited,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $lesson->refresh();
    expect($lesson->subtitle_status)->toBe('ready')
        ->and($lesson->subtitle_disk_path)->toBe("subtitles/{$tenant->id}/{$lesson->id}.vtt");
    expect(Storage::disk('local')->get($lesson->subtitle_disk_path))->toBe($edited);
});

it('does not touch subtitle state when the form is saved with no subtitle text (non-video lesson)', function () {
    [, $lesson, $instructor] = subtitleEditFixture(['type' => 'pdf', 'video_status' => null]);

    $this->actingAs($instructor)
        ->get("http://physics.lms.test/teach/lessons/{$lesson->id}/edit")
        ->assertOk();

    Livewire::test(EditLesson::class, ['record' => $lesson->id])
        ->fillForm([
            'title' => 'Velocity',
            'type' => 'pdf',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($lesson->fresh()->subtitle_status)->toBe('none');
});

it('serves a ready subtitle file through the signed subtitle route', function () {
    [$tenant, $lesson, , $student] = subtitleEditFixture();

    $vtt = "WEBVTT\n\n00:00:00.000 --> 00:00:05.000\nHello.";
    $lesson->attachSubtitleText($vtt);

    $signedUrl = URL::temporarySignedRoute('watch.subtitle', now()->addMinutes(10), [
        'subdomain' => 'physics',
        'lesson' => $lesson->id,
    ]);

    $this->actingAs($student)->get($signedUrl)
        ->assertOk()
        ->assertSee('Hello.', false);
});

it('404s the subtitle route when no subtitle has been generated yet', function () {
    [, $lesson, , $student] = subtitleEditFixture();

    $signedUrl = URL::temporarySignedRoute('watch.subtitle', now()->addMinutes(10), [
        'subdomain' => 'physics',
        'lesson' => $lesson->id,
    ]);

    $this->actingAs($student)->get($signedUrl)->assertNotFound();
});

it('renders a track element on the player once the subtitle is ready', function () {
    [, $lesson, , $student] = subtitleEditFixture();
    $lesson->attachSubtitleText("WEBVTT\n\n00:00:00.000 --> 00:00:05.000\nHello.");

    $this->actingAs($student)
        ->get("http://physics.lms.test/lessons/{$lesson->id}")
        ->assertOk()
        ->assertSee('kind="subtitles"', false);
});

it('renders no track element on the player when no subtitle exists', function () {
    [, $lesson, , $student] = subtitleEditFixture();

    $this->actingAs($student)
        ->get("http://physics.lms.test/lessons/{$lesson->id}")
        ->assertOk()
        ->assertDontSee('kind="subtitles"', false);
});
