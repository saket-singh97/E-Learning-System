<?php

use App\Domains\Content\Jobs\CloseStaleLessonStreamSessions;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\LessonStreamSession;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

function concurrentStreamFixture(): array
{
    test()->seed(RoleSeeder::class);
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);

    Storage::fake('local');

    $makeLesson = function (string $title) use ($tenant, $section) {
        $lesson = Lesson::create([
            'course_section_id' => $section->id,
            'title' => $title,
            'type' => 'video',
            'position' => 0,
            'video_status' => 'ready',
        ]);
        $lesson->update(['video_disk_path' => "videos/{$tenant->id}/{$lesson->id}.mp4"]);
        Storage::disk('local')->put($lesson->video_disk_path, 'fake video bytes');

        return $lesson->fresh();
    };

    $lessonA = $makeLesson('Velocity');
    $lessonB = $makeLesson('Acceleration');

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    return [$tenant, $lessonA, $lessonB, $student];
}

function heartbeatUrl(Lesson $lesson): string
{
    return URL::route('watch.heartbeat', ['subdomain' => 'physics', 'lesson' => $lesson->id]);
}

function signedStreamUrl(Lesson $lesson): string
{
    return URL::temporarySignedRoute('watch.stream', now()->addMinutes(10), [
        'subdomain' => 'physics',
        'lesson' => $lesson->id,
    ]);
}

it('lets a heartbeat on the same lesson repeat without conflict', function () {
    [, $lessonA, , $student] = concurrentStreamFixture();

    $this->actingAs($student)->postJson(heartbeatUrl($lessonA), ['start_s' => 0, 'end_s' => 10])->assertOk();
    $this->actingAs($student)->postJson(heartbeatUrl($lessonA), ['start_s' => 10, 'end_s' => 20])->assertOk();

    expect(LessonStreamSession::count())->toBe(1);
});

it('409s a heartbeat on a different lesson while the first stream is still active', function () {
    [, $lessonA, $lessonB, $student] = concurrentStreamFixture();

    $this->actingAs($student)->postJson(heartbeatUrl($lessonA), ['start_s' => 0, 'end_s' => 10])->assertOk();

    $this->actingAs($student)
        ->postJson(heartbeatUrl($lessonB), ['start_s' => 0, 'end_s' => 10])
        ->assertStatus(409);
});

it('409s streaming a different lesson while the first stream is still active', function () {
    [, $lessonA, $lessonB, $student] = concurrentStreamFixture();

    $this->actingAs($student)->postJson(heartbeatUrl($lessonA), ['start_s' => 0, 'end_s' => 10])->assertOk();

    $this->actingAs($student)
        ->get(signedStreamUrl($lessonB))
        ->assertStatus(409);
});

it('allows a second lesson once the first stream has gone stale past the timeout', function () {
    [, $lessonA, $lessonB, $student] = concurrentStreamFixture();

    $this->actingAs($student)->postJson(heartbeatUrl($lessonA), ['start_s' => 0, 'end_s' => 10])->assertOk();

    LessonStreamSession::sole()->update([
        'last_heartbeat_at' => now()->subSeconds(config('content.stream_session_timeout_seconds') + 1),
    ]);

    $this->actingAs($student)
        ->postJson(heartbeatUrl($lessonB), ['start_s' => 0, 'end_s' => 10])
        ->assertOk();

    expect(LessonStreamSession::where('lesson_id', $lessonB->id)->where('user_id', $student->id)->exists())->toBeTrue();
});

it('sweeps a stale session row closed via CloseStaleLessonStreamSessions', function () {
    [, $lessonA, , $student] = concurrentStreamFixture();

    $this->actingAs($student)->postJson(heartbeatUrl($lessonA), ['start_s' => 0, 'end_s' => 10])->assertOk();

    LessonStreamSession::sole()->update([
        'last_heartbeat_at' => now()->subSeconds(config('content.stream_session_timeout_seconds') + 1),
    ]);

    app(CurrentTenant::class)->clear();
    (new CloseStaleLessonStreamSessions)->handle();

    expect(LessonStreamSession::sole()->ended_at)->not->toBeNull();
});
