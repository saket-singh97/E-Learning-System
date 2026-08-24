<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchGeofence;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Content\Models\AttendanceRecord;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Livewire\GeofenceCheckIn;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Live Q&A', 'type' => 'live_session', 'position' => 0]);

    $this->batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-batch',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);

    $this->session = BatchSession::create([
        'batch_id' => $this->batch->id,
        'lesson_id' => $lesson->id,
        'starts_at' => '2026-09-08 10:00:00',
        'duration_minutes' => 60,
        'position' => 0,
        'live_provider' => 'external_link',
        'provider_meeting_ref' => 'https://meet.example.com/room-1',
    ]);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');
});

it('writes a present record for an inside-radius check-in', function () {
    BatchGeofence::create(['batch_id' => $this->batch->id, 'latitude' => 12.9752, 'longitude' => 77.6068, 'radius_meters' => 100]);

    Livewire::actingAs($this->student)
        ->test(GeofenceCheckIn::class, ['session' => $this->session])
        ->call('checkIn', 12.9753, 77.6068)
        ->assertSet('checkedIn', true)
        ->assertSet('error', null);

    $record = AttendanceRecord::where('attendable_type', BatchSession::class)
        ->where('attendable_id', $this->session->id)
        ->where('user_id', $this->student->id)
        ->sole();

    expect($record->status)->toBe('present');
    expect($record->source)->toBe('geofence_checkin');
    expect($record->role)->toBe('student');
});

it('refuses an outside-radius check-in with a visible error, without writing a record', function () {
    BatchGeofence::create(['batch_id' => $this->batch->id, 'latitude' => 12.9752, 'longitude' => 77.6068, 'radius_meters' => 100]);

    Livewire::actingAs($this->student)
        ->test(GeofenceCheckIn::class, ['session' => $this->session])
        ->call('checkIn', 12.9852, 77.6068)
        ->assertSet('checkedIn', false)
        ->assertSet('error', "You're outside this session's check-in radius.");

    expect(AttendanceRecord::where('attendable_id', $this->session->id)->count())->toBe(0);
});

it('always accepts a check-in when the batch has no geofence configured', function () {
    Livewire::actingAs($this->student)
        ->test(GeofenceCheckIn::class, ['session' => $this->session])
        ->call('checkIn', 0.0, 0.0)
        ->assertSet('checkedIn', true);

    expect(AttendanceRecord::where('attendable_id', $this->session->id)->where('user_id', $this->student->id)->count())->toBe(1);
});

it('is idempotent — a duplicate check-in for the same session updates the existing row, not a new one', function () {
    Livewire::actingAs($this->student)
        ->test(GeofenceCheckIn::class, ['session' => $this->session])
        ->call('checkIn', 0.0, 0.0)
        ->call('checkIn', 0.0, 0.0);

    expect(AttendanceRecord::where('attendable_id', $this->session->id)->where('user_id', $this->student->id)->count())->toBe(1);
});
