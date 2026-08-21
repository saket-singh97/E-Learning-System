<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\Drivers\GoogleMeetProvider;
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
        'live_provider' => 'google_meet',
    ]);

    $this->driver = new GoogleMeetProvider;
});

it('refuses to act when no platform Google Calendar credential is configured', function () {
    config(['services.google.calendar_refresh_token' => null]);

    expect(fn () => $this->driver->createRoom($this->session))->toThrow(RuntimeException::class);
});

it('creates a real calendar event with conferenceData and returns the hangoutLink', function () {
    config([
        'services.google.client_id' => 'test-client-id',
        'services.google.client_secret' => 'test-client-secret',
        'services.google.calendar_refresh_token' => 'test-refresh-token',
    ]);

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh-access-token', 'expires_in' => 3600], 200),
        'www.googleapis.com/calendar/v3/*' => Http::response(['hangoutLink' => 'https://meet.google.com/abc-defg-hij'], 200),
    ]);

    $ref = $this->driver->createRoom($this->session);

    expect($ref)->toBe('https://meet.google.com/abc-defg-hij');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth2.googleapis.com/token')
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'test-refresh-token');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'calendars/primary/events')
        && str_contains($request->url(), 'conferenceDataVersion=1')
        && $request->hasHeader('Authorization', 'Bearer fresh-access-token')
        && $request['conferenceData']['createRequest']['conferenceSolutionKey']['type'] === 'hangoutsMeet');
});

it('returns the same hangoutLink for a host and a student', function () {
    config([
        'services.google.client_id' => 'test-client-id',
        'services.google.client_secret' => 'test-client-secret',
        'services.google.calendar_refresh_token' => 'test-refresh-token',
    ]);
    $this->session->update(['provider_meeting_ref' => 'https://meet.google.com/abc-defg-hij']);

    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $student->assignRole('student');

    expect($this->driver->joinUrl($this->session->fresh(), $instructor))->toBe('https://meet.google.com/abc-defg-hij');
    expect($this->driver->joinUrl($this->session->fresh(), $student))->toBe('https://meet.google.com/abc-defg-hij');
});

it('no-ops markEnded — Calendar API has no end-meeting call', function () {
    Http::fake();

    $this->driver->markEnded($this->session);

    Http::assertNothingSent();
});

it('resolves google_meet through BatchSession::resolveLiveClassProvider()', function () {
    expect($this->session->resolveLiveClassProvider())->toBeInstanceOf(GoogleMeetProvider::class);
});
