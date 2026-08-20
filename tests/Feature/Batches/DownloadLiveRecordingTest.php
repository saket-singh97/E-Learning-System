<?php

use App\Domains\Batches\Jobs\DownloadLiveRecording;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\InstituteZoomConnection;
use App\Domains\Batches\Models\LiveRecording;
use App\Domains\Content\Events\LessonVideoReady;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Mechanics', 'position' => 0]);
    $this->lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Live class', 'type' => 'live_session', 'position' => 0]);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-'.uniqid(),
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'draft',
    ]);
    $session = BatchSession::create([
        'batch_id' => $batch->id,
        'lesson_id' => $this->lesson->id,
        'starts_at' => now()->addDay(),
        'duration_minutes' => 60,
        'position' => 0,
        'live_provider' => 'zoom_meetings_oauth',
        'provider_meeting_ref' => '987654321',
    ]);
    InstituteZoomConnection::create([
        'account_id' => 'acct-1',
        'access_token' => 'zoom-access-token',
        'refresh_token' => 'zoom-refresh-token',
        'token_expires_at' => now()->addHour(),
        'connected_by_user_id' => User::factory()->create(['tenant_id' => $this->tenant->id])->id,
    ]);
    $this->recording = LiveRecording::create([
        'batch_session_id' => $session->id,
        'provider_recording_id' => 'rec-uuid-1',
        'status' => 'pending',
    ]);
});

it('downloads the recording onto the lesson video_disk_path and fires LessonVideoReady', function () {
    Event::fake();
    Http::fake([
        'api.zoom.us/v2/meetings/987654321/recordings' => Http::response([
            'recording_files' => [['file_type' => 'MP4', 'download_url' => 'https://zoom.us/rec/download/abc']],
        ], 200),
        'zoom.us/rec/download/abc' => Http::response('fake-mp4-bytes', 200),
    ]);

    (new DownloadLiveRecording($this->recording->id, $this->tenant->id))->handle();

    $this->recording->refresh();
    $expectedPath = "videos/{$this->tenant->id}/{$this->lesson->id}.mp4";

    expect($this->recording->status)->toBe('ready')
        ->and($this->recording->disk_path)->toBe($expectedPath);
    expect($this->lesson->fresh()->video_disk_path)->toBe($expectedPath)
        ->and($this->lesson->fresh()->video_status)->toBe('ready');
    Storage::disk('local')->assertExists($expectedPath);

    Event::assertDispatched(LessonVideoReady::class, fn ($event) => $event->lessonId === $this->lesson->id
        && $event->tenantId === $this->tenant->id);
});

it('marks the recording failed when no MP4 file is found', function () {
    Http::fake(['api.zoom.us/v2/meetings/987654321/recordings' => Http::response(['recording_files' => []], 200)]);

    (new DownloadLiveRecording($this->recording->id, $this->tenant->id))->handle();

    expect($this->recording->fresh()->status)->toBe('failed');
});

it('no-ops when the recording is already ready', function () {
    $this->recording->update(['status' => 'ready']);
    Http::fake();

    (new DownloadLiveRecording($this->recording->id, $this->tenant->id))->handle();

    Http::assertNothingSent();
});
