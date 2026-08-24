<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\DeviceWebhookCredential;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\AttendanceRecord;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Mechanics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Live class', 'type' => 'live_session', 'position' => 0]);
    $this->batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-'.uniqid(),
        'starts_on' => now()->subDay()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    $this->session = BatchSession::create([
        'batch_id' => $this->batch->id,
        'lesson_id' => $lesson->id,
        'starts_at' => now()->subMinutes(10),
        'duration_minutes' => 60,
        'position' => 0,
        'live_provider' => 'external_link',
        'provider_meeting_ref' => 'https://meet.example.com/live',
    ]);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'student@example.com']);
    Enrolment::create(['batch_id' => $this->batch->id, 'user_id' => $this->student->id, 'status' => 'active', 'source' => 'manual_add', 'requested_at' => now()]);

    $this->credential = DeviceWebhookCredential::create([
        'batch_id' => $this->batch->id,
        'device_label' => 'Front gate',
        'webhook_secret' => 'whsec_device_test',
    ]);

    app(CurrentTenant::class)->clear();
});

function deviceSignedPost(int $credentialId, array $body, string $secret = 'whsec_device_test')
{
    $json = json_encode($body);
    $timestamp = (string) time();
    $signature = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$json}", $secret);

    return test()->postJson("/api/webhooks/device/{$credentialId}", $body, [
        'X-Device-Timestamp' => $timestamp,
        'X-Device-Signature' => $signature,
    ]);
}

it('accepts a validly-signed check-in and writes a present record', function () {
    deviceSignedPost($this->credential->id, [
        'device_label' => 'Front gate',
        'user_identifier' => 'student@example.com',
        'event' => 'check_in',
        'timestamp' => now()->toIso8601String(),
    ])->assertOk();

    app(CurrentTenant::class)->set($this->tenant);
    $record = AttendanceRecord::where('attendable_type', BatchSession::class)
        ->where('attendable_id', $this->session->id)
        ->where('user_id', $this->student->id)
        ->sole();

    expect($record->status)->toBe('present');
    expect($record->source)->toBe('device_webhook');
});

it('refuses a bad signature with 401 and writes nothing', function () {
    test()->postJson("/api/webhooks/device/{$this->credential->id}", [
        'device_label' => 'Front gate',
        'user_identifier' => 'student@example.com',
        'event' => 'check_in',
        'timestamp' => now()->toIso8601String(),
    ], ['X-Device-Timestamp' => (string) time(), 'X-Device-Signature' => 'v0=bogus'])
        ->assertStatus(401);

    app(CurrentTenant::class)->set($this->tenant);
    expect(AttendanceRecord::count())->toBe(0);
});

it('refuses a missing signature header with 401', function () {
    test()->postJson("/api/webhooks/device/{$this->credential->id}", [
        'device_label' => 'Front gate',
        'user_identifier' => 'student@example.com',
        'event' => 'check_in',
        'timestamp' => now()->toIso8601String(),
    ])->assertStatus(401);
});

it('ignores an unknown user_identifier without a fatal error', function () {
    deviceSignedPost($this->credential->id, [
        'device_label' => 'Front gate',
        'user_identifier' => 'nobody@example.com',
        'event' => 'check_in',
        'timestamp' => now()->toIso8601String(),
    ])->assertOk();

    app(CurrentTenant::class)->set($this->tenant);
    expect(AttendanceRecord::count())->toBe(0);
});

it('cannot write into another tenant even with a correctly-signed request for that tenants own credential', function () {
    $otherTenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($otherTenant);
    $otherStudent = User::factory()->create(['tenant_id' => $otherTenant->id, 'email' => 'other-student@example.com']);
    app(CurrentTenant::class)->clear();

    // Signed correctly for $this->credential (tenant A's own secret), but
    // the identifier belongs to a user in a different tenant — the
    // credential's own tenant-scoped user lookup must not cross over.
    deviceSignedPost($this->credential->id, [
        'device_label' => 'Front gate',
        'user_identifier' => 'other-student@example.com',
        'event' => 'check_in',
        'timestamp' => now()->toIso8601String(),
    ])->assertOk();

    app(CurrentTenant::class)->set($otherTenant);
    expect(AttendanceRecord::where('user_id', $otherStudent->id)->count())->toBe(0);
});
