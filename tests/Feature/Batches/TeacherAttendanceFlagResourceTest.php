<?php

use App\Domains\Batches\Filament\Resources\TeacherAttendanceFlags\Pages\ListTeacherAttendanceFlags;
use App\Domains\Batches\Filament\Resources\TeacherAttendanceFlags\TeacherAttendanceFlagResource;
use App\Domains\Batches\Jobs\TeacherAttendanceSweep;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\TeacherAttendanceFlag;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
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

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin->assignRole('institute_admin');

    $this->instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->instructor->assignRole('instructor');

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $this->batch = Batch::create([
        'course_id' => $course->id,
        'instructor_id' => $this->instructor->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->subMonth()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    $this->session = BatchSession::create([
        'batch_id' => $this->batch->id,
        'starts_at' => now()->subHours(2),
        'duration_minutes' => 60,
        'position' => 0,
    ]);

    app(CurrentTenant::class)->clear();
    app(TeacherAttendanceSweep::class)->handle();
    app(CurrentTenant::class)->set($this->tenant);

    $this->flag = TeacherAttendanceFlag::where('batch_session_id', $this->session->id)->sole();

    $this->actingAs($this->admin)
        ->get(TeacherAttendanceFlagResource::getUrl('index', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();
});

it('lists a sweep-created flag in the admin queue', function () {
    Livewire::test(ListTeacherAttendanceFlags::class)
        ->assertCanSeeTableRecords([$this->flag]);
});

it('resolves a flag without reassignment, leaving the batch instructor unchanged', function () {
    Livewire::actingAs($this->admin)
        ->test(ListTeacherAttendanceFlags::class)
        ->callTableAction('resolve', $this->flag, data: [
            'reassign_instructor_id' => null,
        ])
        ->assertHasNoTableActionErrors();

    expect($this->flag->fresh()->status)->toBe('resolved');
    expect($this->flag->fresh()->resolved_by_user_id)->toBe($this->admin->id);
    expect($this->batch->fresh()->instructor_id)->toBe($this->instructor->id);
});

it('resolves a flag with a reassignment, updating the batch instructor and closing the flag', function () {
    $substitute = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $substitute->assignRole('instructor');

    Livewire::actingAs($this->admin)
        ->test(ListTeacherAttendanceFlags::class)
        ->callTableAction('resolve', $this->flag, data: [
            'reassign_instructor_id' => $substitute->id,
        ])
        ->assertHasNoTableActionErrors();

    expect($this->flag->fresh()->status)->toBe('resolved');
    expect($this->batch->fresh()->instructor_id)->toBe($substitute->id);
});

it('hides the resolve action for an already-resolved flag', function () {
    $this->flag->update(['status' => 'resolved']);

    Livewire::actingAs($this->admin)
        ->test(ListTeacherAttendanceFlags::class)
        ->assertTableActionHidden('resolve', $this->flag);
});
