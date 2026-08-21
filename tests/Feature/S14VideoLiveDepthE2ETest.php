<?php

use App\Domains\AI\Models\LessonArtifact;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Batches\Models\InstituteZoomConnection;
use App\Domains\Batches\Models\LiveRecording;
use App\Domains\Batches\Services\LivePollService;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

/**
 * S14 B18 — lifecycle E2E, same posture S11 B16/S12 B14/S13 B17 each
 * already established: proves the real chain fires end to end, not just
 * that each block's own model relationships line up in isolation.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    config([
        'services.zoom.oauth_client_id' => 'test-client-id',
        'services.zoom.oauth_client_secret' => 'test-client-secret',
        'services.zoom.webhook_secret' => 'whsec_test',
    ]);
});

it('runs Zoom OAuth connect through to a playable recording (G5-G8, G10)', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);

    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');

    // 1. Zoom OAuth connect (B6) — real controller flow, tokens faked.
    Http::fake([
        'zoom.us/oauth/token' => Http::response([
            'access_token' => 'zoom-access-token',
            'refresh_token' => 'zoom-refresh-token',
            'expires_in' => 3600,
        ], 200),
        'api.zoom.us/v2/users/me' => Http::response(['account_id' => 'acct-1'], 200),
    ]);

    test()->actingAs($admin)
        ->get('http://physics.lms.test/zoom/connect')
        ->assertRedirect();

    test()->withSession([
        'zoom_oauth.tenant_id' => $tenant->id,
        'zoom_oauth.user_id' => $admin->id,
    ])->get('http://lms.test/auth/zoom/callback?code=auth-code')
        ->assertRedirect();

    app(CurrentTenant::class)->set($tenant);
    expect(InstituteZoomConnection::where('tenant_id', $tenant->id)->exists())->toBeTrue();

    // 2. Schedule a live_session on the new provider (B7) — createLiveRoom()
    // mints a real Zoom meeting and stores the ref.
    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Mechanics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Live class', 'type' => 'live_session', 'position' => 0]);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-'.uniqid(),
        'starts_on' => now()->subDay()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    $session = BatchSession::create([
        'batch_id' => $batch->id,
        'lesson_id' => $lesson->id,
        'starts_at' => now()->subHour(),
        'duration_minutes' => 60,
        'position' => 0,
        'live_provider' => 'zoom_meetings_oauth',
    ]);

    Http::fake([
        'zoom.us/oauth/token' => Http::response(['access_token' => 'zoom-access-token', 'refresh_token' => 'zoom-refresh-token', 'expires_in' => 3600], 200),
        'api.zoom.us/v2/users/me' => Http::response(['account_id' => 'acct-1'], 200),
        'api.zoom.us/v2/users/me/meetings' => Http::response(['id' => 987654321], 201),
    ]);

    $session->createLiveRoom();

    expect($session->fresh()->provider_meeting_ref)->toBe('987654321');

    // 3. Zoom's recording-completed webhook (B8) — real signed HTTP post.
    $body = ['event' => 'recording.completed', 'payload' => ['object' => ['id' => '987654321', 'uuid' => 'rec-uuid-1']]];
    $json = json_encode($body);
    $timestamp = (string) time();
    $signature = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$json}", 'whsec_test');

    Http::fake([
        'zoom.us/oauth/token' => Http::response(['access_token' => 'zoom-access-token', 'refresh_token' => 'zoom-refresh-token', 'expires_in' => 3600], 200),
        'api.zoom.us/v2/meetings/987654321/recordings' => Http::response([
            'recording_files' => [['file_type' => 'MP4', 'download_url' => 'https://zoom.us/rec/download/abc']],
        ], 200),
        'zoom.us/rec/download/abc' => Http::response('fake-mp4-bytes', 200),
    ]);

    test()->postJson('/api/webhooks/zoom-recording', $body, [
        'x-zm-request-timestamp' => $timestamp,
        'x-zm-signature' => $signature,
    ])->assertOk();

    // 4. Downloaded (B9, QUEUE_CONNECTION=sync ran it inline) and ingested
    // (S3's existing pipeline, unchanged, fired by the same LessonVideoReady
    // DownloadLiveRecording already dispatches).
    app(CurrentTenant::class)->set($tenant);

    $recording = LiveRecording::where('batch_session_id', $session->id)->sole();
    expect($recording->status)->toBe('ready');

    $freshLesson = $lesson->fresh();
    expect($freshLesson->video_status)->toBe('ready')
        ->and($freshLesson->video_disk_path)->toBe("videos/{$tenant->id}/{$lesson->id}.mp4");

    $artifact = LessonArtifact::where('lesson_id', $lesson->id)->sole();
    expect($artifact->status)->toBe('ready');

    // 5. Playable (G6/B10) — an enrolled student streams it through the
    // existing signed-URL path, unchanged.
    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');
    Enrolment::create([
        'batch_id' => $batch->id,
        'user_id' => $student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    $streamUrl = URL::temporarySignedRoute('watch.stream', now()->addMinutes(10), [
        'subdomain' => 'physics',
        'lesson' => $lesson->id,
    ]);

    test()->actingAs($student)->get($streamUrl)->assertOk();
});

it('runs a live poll instructor-launch through student-response to aggregation (G9)', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Mechanics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Live class', 'type' => 'live_session', 'position' => 0]);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-'.uniqid(),
        'starts_on' => now()->subDay()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    $session = BatchSession::create([
        'batch_id' => $batch->id,
        'lesson_id' => $lesson->id,
        'starts_at' => now()->subMinutes(5),
        'duration_minutes' => 60,
        'position' => 0,
        'live_provider' => 'external_link',
        'provider_meeting_ref' => 'https://example.test/meet',
    ]);

    $instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $instructor->assignRole('instructor');

    [$studentA, $studentB, $studentC] = User::factory()->count(3)->create(['tenant_id' => $tenant->id]);
    foreach ([$studentA, $studentB, $studentC] as $student) {
        $student->assignRole('student');
    }
    foreach ([$studentA, $studentB] as $enrolledStudent) {
        Enrolment::create([
            'batch_id' => $batch->id,
            'user_id' => $enrolledStudent->id,
            'status' => 'active',
            'source' => 'manual_add',
            'requested_at' => now(),
        ]);
    }
    // $studentC is deliberately left unenrolled.

    $service = app(LivePollService::class);

    $poll = $service->create($session, $instructor, 'Which topic next?', ['Kinematics', 'Dynamics']);
    $service->open($poll, $instructor);

    $optionA = $poll->options->first();

    Livewire\Livewire::actingAs($studentA)
        ->test(App\Livewire\LivePollResponse::class, ['session' => $session])
        ->call('submit', $optionA->id);

    Livewire\Livewire::actingAs($studentB)
        ->test(App\Livewire\LivePollResponse::class, ['session' => $session])
        ->call('submit', $optionA->id);

    // Non-enrolled student is refused — canJoinLive() denies, no row written.
    Livewire\Livewire::actingAs($studentC)
        ->test(App\Livewire\LivePollResponse::class, ['session' => $session])
        ->call('submit', $optionA->id);

    $service->close($poll, $instructor);

    expect($poll->fresh()->status)->toBe('closed')
        ->and($poll->options->first()->responses()->count())->toBe(2)
        ->and($poll->options->last()->responses()->count())->toBe(0);

    $board = Livewire\Livewire::actingAs($instructor)
        ->test(App\Livewire\LivePollBoard::class, ['session' => $session]);

    $board->assertSee('2 response(s)');
});
