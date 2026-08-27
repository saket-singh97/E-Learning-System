<?php

use App\Domains\Analytics\Models\GamificationProfile;
use App\Domains\Analytics\Services\LeaderboardService;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $this->batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch A',
        'slug' => 'batch-a-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
});

function enrolStudentInBatch(Batch $batch, User $student, string $status = 'active'): void
{
    Enrolment::create(['batch_id' => $batch->id, 'user_id' => $student->id, 'status' => $status, 'source' => 'manual_add', 'requested_at' => now()]);
}

it('ranks enrolled students by xp descending', function () {
    $top = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $mid = User::factory()->create(['tenant_id' => $this->tenant->id]);
    enrolStudentInBatch($this->batch, $top);
    enrolStudentInBatch($this->batch, $mid);

    GamificationProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $top->id, 'xp_total' => 200, 'level' => 3]);
    GamificationProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $mid->id, 'xp_total' => 50, 'level' => 1]);

    $rankings = app(LeaderboardService::class)->rankingsFor($this->batch);

    expect($rankings->pluck('user_id')->all())->toBe([$top->id, $mid->id])
        ->and($rankings->first()['rank'])->toBe(1)
        ->and($rankings->last()['rank'])->toBe(2);
});

it('excludes a student whose enrolment is not active', function () {
    $waitlisted = User::factory()->create(['tenant_id' => $this->tenant->id]);
    enrolStudentInBatch($this->batch, $waitlisted, 'waitlisted');
    GamificationProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $waitlisted->id, 'xp_total' => 999, 'level' => 9]);

    $rankings = app(LeaderboardService::class)->rankingsFor($this->batch);

    expect($rankings)->toBeEmpty();
});

it('never leaks another tenant\'s student into a batch\'s leaderboard', function () {
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    enrolStudentInBatch($this->batch, $student);
    GamificationProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $student->id, 'xp_total' => 10, 'level' => 1]);

    $otherTenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($otherTenant);
    $otherCourse = Course::create(['title' => 'Chemistry 101', 'slug' => 'chemistry-101', 'status' => 'published']);
    $otherBatch = Batch::create([
        'course_id' => $otherCourse->id,
        'title' => 'Batch B',
        'slug' => 'batch-b-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    $otherStudent = User::factory()->create(['tenant_id' => $otherTenant->id]);
    enrolStudentInBatch($otherBatch, $otherStudent);
    GamificationProfile::create(['tenant_id' => $otherTenant->id, 'user_id' => $otherStudent->id, 'xp_total' => 9999, 'level' => 99]);
    app(CurrentTenant::class)->set($this->tenant);

    $rankings = app(LeaderboardService::class)->rankingsFor($this->batch);

    expect($rankings)->toHaveCount(1)->and($rankings->first()['user_id'])->toBe($student->id);
});
