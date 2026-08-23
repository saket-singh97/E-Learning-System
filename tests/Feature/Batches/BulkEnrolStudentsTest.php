<?php

use App\Domains\Batches\Jobs\BulkEnrolStudents;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $this->batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch A',
        'slug' => 'batch-a',
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin->assignRole('institute_admin');
});

it('enrols each existing student in the email list', function () {
    $studentA = User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'a@example.com']);
    $studentA->assignRole('student');
    $studentB = User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'b@example.com']);
    $studentB->assignRole('student');

    BulkEnrolStudents::dispatchSync($this->tenant->id, $this->batch->id, ['a@example.com', 'b@example.com'], $this->admin->id);

    expect(Enrolment::where('batch_id', $this->batch->id)->where('user_id', $studentA->id)->where('status', 'active')->exists())->toBeTrue();
    expect(Enrolment::where('batch_id', $this->batch->id)->where('user_id', $studentB->id)->where('status', 'active')->exists())->toBeTrue();
});

it('skips an email with no matching user, without failing the job', function () {
    $studentA = User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'a@example.com']);
    $studentA->assignRole('student');

    BulkEnrolStudents::dispatchSync($this->tenant->id, $this->batch->id, ['a@example.com', 'nobody@example.com'], $this->admin->id);

    expect(Enrolment::where('batch_id', $this->batch->id)->count())->toBe(1);
});

it('skips an email registered under a different tenant', function () {
    $otherTenant = Tenant::factory()->create();
    User::factory()->create(['tenant_id' => $otherTenant->id, 'email' => 'outsider@example.com']);

    BulkEnrolStudents::dispatchSync($this->tenant->id, $this->batch->id, ['outsider@example.com'], $this->admin->id);

    expect(Enrolment::where('batch_id', $this->batch->id)->count())->toBe(0);
});

it('is idempotent for an already-enrolled student', function () {
    $student = User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'a@example.com']);
    $student->assignRole('student');
    Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    BulkEnrolStudents::dispatchSync($this->tenant->id, $this->batch->id, ['a@example.com'], $this->admin->id);

    expect(Enrolment::where('batch_id', $this->batch->id)->where('user_id', $student->id)->count())->toBe(1);
});
