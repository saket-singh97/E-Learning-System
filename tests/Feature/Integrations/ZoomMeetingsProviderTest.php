<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\InstituteZoomConnection;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\Drivers\ZoomMeetingsProvider;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
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
        'title' => 'Kinematics recap',
        'starts_at' => now()->addDay(),
        'duration_minutes' => 60,
        'position' => 0,
        'live_provider' => 'zoom_meetings_oauth',
    ]);

    $this->driver = new ZoomMeetingsProvider;
});

it('refuses to act when the tenant has no connected Zoom account', function () {
    expect(fn () => $this->driver->createRoom($this->session))->toThrow(RuntimeException::class);
});

it('creates a real zoom meeting via the connected institute token', function () {
    InstituteZoomConnection::create([
        'account_id' => 'acct-1',
        'access_token' => 'zoom-access-token',
        'refresh_token' => 'zoom-refresh-token',
        'token_expires_at' => now()->addHour(),
        'connected_by_user_id' => User::factory()->create(['tenant_id' => $this->tenant->id])->id,
    ]);

    Http::fake(['api.zoom.us/v2/users/me/meetings' => Http::response(['id' => 987654321], 201)]);

    $ref = $this->driver->createRoom($this->session);

    expect($ref)->toBe('987654321');
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer zoom-access-token')
        && $request['topic'] === 'Kinematics recap');
});

it('resolves start_url for a host and join_url for a student', function () {
    InstituteZoomConnection::create([
        'account_id' => 'acct-1',
        'access_token' => 'zoom-access-token',
        'refresh_token' => 'zoom-refresh-token',
        'token_expires_at' => now()->addHour(),
        'connected_by_user_id' => User::factory()->create(['tenant_id' => $this->tenant->id])->id,
    ]);
    $this->session->update(['provider_meeting_ref' => '987654321']);

    Http::fake(['api.zoom.us/v2/meetings/987654321' => Http::response([
        'start_url' => 'https://zoom.us/s/987654321?zak=host-token',
        'join_url' => 'https://zoom.us/j/987654321',
    ], 200)]);

    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $student->assignRole('student');

    expect($this->driver->joinUrl($this->session->fresh(), $instructor))->toBe('https://zoom.us/s/987654321?zak=host-token');
    expect($this->driver->joinUrl($this->session->fresh(), $student))->toBe('https://zoom.us/j/987654321');
});

it('ends the meeting on markEnded when a meeting ref exists', function () {
    InstituteZoomConnection::create([
        'account_id' => 'acct-1',
        'access_token' => 'zoom-access-token',
        'refresh_token' => 'zoom-refresh-token',
        'token_expires_at' => now()->addHour(),
        'connected_by_user_id' => User::factory()->create(['tenant_id' => $this->tenant->id])->id,
    ]);
    $this->session->update(['provider_meeting_ref' => '987654321']);

    Http::fake(['api.zoom.us/v2/meetings/987654321/status' => Http::response([], 204)]);

    $this->driver->markEnded($this->session->fresh());

    Http::assertSent(fn ($request) => $request->url() === 'https://api.zoom.us/v2/meetings/987654321/status'
        && $request['action'] === 'end');
});

it('no-ops markEnded when no meeting was ever created', function () {
    Http::fake();

    $this->driver->markEnded($this->session);

    Http::assertNothingSent();
});

it('resolves zoom_meetings_oauth through BatchSession::resolveLiveClassProvider()', function () {
    expect($this->session->resolveLiveClassProvider())->toBeInstanceOf(ZoomMeetingsProvider::class);
});
