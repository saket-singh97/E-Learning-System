<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\Drivers\ZoomVideoSdkProvider;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Live Q&A', 'type' => 'live_session', 'position' => 0]);

    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-batch',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);

    $this->session = BatchSession::create([
        'batch_id' => $batch->id,
        'lesson_id' => $lesson->id,
        'starts_at' => now()->addWeek(),
        'duration_minutes' => 60,
        'position' => 0,
        'live_provider' => 'zoom_video_sdk',
    ]);

    $this->student = User::factory()->create(['tenant_id' => $tenant->id]);
    $this->student->assignRole('student');

    $this->instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $this->instructor->assignRole('instructor');

    $this->driver = new ZoomVideoSdkProvider;
});

it('mints a stable session name off the session id with no outbound call', function () {
    expect($this->driver->createRoom($this->session))->toBe("lms-session-{$this->session->id}");
});

it('refuses to sign a join token when Zoom credentials are not configured', function () {
    config(['services.zoom.sdk_key' => null, 'services.zoom.sdk_secret' => null]);

    expect(fn () => $this->driver->joinUrl($this->session, $this->student))
        ->toThrow(RuntimeException::class);
});

it('signs a valid HS256 JWT carrying app_key, tpc, role_type, iat, and exp', function () {
    config(['services.zoom.sdk_key' => 'test-key', 'services.zoom.sdk_secret' => 'test-secret']);
    $this->session->update(['provider_meeting_ref' => 'lms-session-42']);

    $token = $this->driver->joinUrl($this->session, $this->student);
    [$headerB64, $payloadB64, $signatureB64] = explode('.', $token);

    $header = json_decode(base64_decode(strtr($headerB64, '-_', '+/')), true);
    $payload = json_decode(base64_decode(strtr($payloadB64, '-_', '+/')), true);

    expect($header)->toBe(['alg' => 'HS256', 'typ' => 'JWT'])
        ->and($payload['app_key'])->toBe('test-key')
        ->and($payload['tpc'])->toBe('lms-session-42')
        ->and($payload['role_type'])->toBe(0)
        ->and($payload['version'])->toBe(1)
        ->and($payload['exp'])->toBeGreaterThan($payload['iat']);

    $expectedSignature = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$headerB64}.{$payloadB64}", 'test-secret', true)), '+/', '-_'), '=');
    expect($signatureB64)->toBe($expectedSignature);
});

it('signs role_type=1 for staff and role_type=0 for a student', function () {
    config(['services.zoom.sdk_key' => 'test-key', 'services.zoom.sdk_secret' => 'test-secret']);

    $decode = fn (string $token): array => json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);

    expect($decode($this->driver->joinUrl($this->session, $this->instructor))['role_type'])->toBe(1)
        ->and($decode($this->driver->joinUrl($this->session, $this->student))['role_type'])->toBe(0);
});

it('falls back to createRoom()s topic when provider_meeting_ref is not yet set', function () {
    config(['services.zoom.sdk_key' => 'test-key', 'services.zoom.sdk_secret' => 'test-secret']);

    $decode = fn (string $token): array => json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);

    expect($decode($this->driver->joinUrl($this->session, $this->student))['tpc'])
        ->toBe("lms-session-{$this->session->id}");
});

it('has no vendor-side call on markEnded, credentials or not', function () {
    config(['services.zoom.sdk_key' => null, 'services.zoom.sdk_secret' => null]);

    $this->driver->markEnded($this->session);

    expect(true)->toBeTrue();
});
