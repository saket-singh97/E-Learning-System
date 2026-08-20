<?php

use App\Domains\Batches\Jobs\DownloadLiveRecording;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\LiveRecording;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.zoom.webhook_secret' => 'whsec_test']);

    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Mechanics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Live class', 'type' => 'live_session', 'position' => 0]);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-'.uniqid(),
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'draft',
    ]);
    $this->session = BatchSession::create([
        'batch_id' => $batch->id,
        'lesson_id' => $lesson->id,
        'starts_at' => now()->addDay(),
        'duration_minutes' => 60,
        'position' => 0,
        'live_provider' => 'zoom_meetings_oauth',
        'provider_meeting_ref' => '987654321',
    ]);

    app(CurrentTenant::class)->clear();
});

function zoomSignedPost($body, string $secret = 'whsec_test')
{
    $json = json_encode($body);
    $timestamp = (string) time();
    $signature = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$json}", $secret);

    return test()->postJson('/api/webhooks/zoom-recording', $body, [
        'x-zm-request-timestamp' => $timestamp,
        'x-zm-signature' => $signature,
    ]);
}

it('answers the endpoint.url_validation handshake without a signature', function () {
    $response = test()->postJson('/api/webhooks/zoom-recording', [
        'event' => 'endpoint.url_validation',
        'payload' => ['plainToken' => 'abc123'],
    ]);

    $response->assertOk();
    expect($response->json('plainToken'))->toBe('abc123');
    expect($response->json('encryptedToken'))->toBe(hash_hmac('sha256', 'abc123', 'whsec_test'));
});

it('rejects a recording.completed event with a bad signature', function () {
    test()->postJson('/api/webhooks/zoom-recording', [
        'event' => 'recording.completed',
        'payload' => ['object' => ['id' => '987654321', 'uuid' => 'rec-uuid-1']],
    ], ['x-zm-request-timestamp' => (string) time(), 'x-zm-signature' => 'v0=bogus'])
        ->assertStatus(401);
});

it('creates a pending live recording and dispatches the download job, bound to its own tenant', function () {
    Queue::fake();

    zoomSignedPost([
        'event' => 'recording.completed',
        'payload' => ['object' => ['id' => '987654321', 'uuid' => 'rec-uuid-1']],
    ])->assertOk();

    app(CurrentTenant::class)->set($this->tenant);
    $recording = LiveRecording::where('batch_session_id', $this->session->id)->sole();

    expect($recording->tenant_id)->toBe($this->tenant->id)
        ->and($recording->provider_recording_id)->toBe('rec-uuid-1')
        ->and($recording->status)->toBe('pending');

    Queue::assertPushed(DownloadLiveRecording::class, fn ($job) => $job->liveRecordingId === $recording->id
        && $job->tenantId === $this->tenant->id);
});

it('does not re-dispatch or duplicate a row for a retried webhook past pending', function () {
    Queue::fake();

    $body = [
        'event' => 'recording.completed',
        'payload' => ['object' => ['id' => '987654321', 'uuid' => 'rec-uuid-1']],
    ];
    zoomSignedPost($body)->assertOk();

    app(CurrentTenant::class)->set($this->tenant);
    LiveRecording::where('batch_session_id', $this->session->id)->sole()->update(['status' => 'ready']);
    app(CurrentTenant::class)->clear();

    zoomSignedPost($body)->assertOk();

    app(CurrentTenant::class)->set($this->tenant);
    expect(LiveRecording::count())->toBe(1);
    Queue::assertPushed(DownloadLiveRecording::class, 1);
});

it('ignores a recording.completed event for an unknown meeting id', function () {
    zoomSignedPost([
        'event' => 'recording.completed',
        'payload' => ['object' => ['id' => 'no-such-meeting', 'uuid' => 'rec-uuid-2']],
    ])->assertOk();

    app(CurrentTenant::class)->set($this->tenant);
    expect(LiveRecording::count())->toBe(0);
});
