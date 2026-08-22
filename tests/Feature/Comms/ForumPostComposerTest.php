<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Comms\Models\ForumThread;
use App\Domains\Comms\Services\ForumPostComposer;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\ForumReplyDepthExceededException;
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
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');

    $this->thread = ForumThread::create([
        'batch_id' => $this->batch->id,
        'title' => 'Week 1 discussion',
        'created_by_user_id' => $this->student->id,
    ]);

    $this->composer = new ForumPostComposer;
});

it('posts a new top-level post at depth 0', function () {
    $post = $this->composer->post($this->thread, null, $this->student, 'First post');

    expect($post->depth)->toBe(0);
    expect($post->parent_id)->toBeNull();
});

it('allows nested replies up to depth 2', function () {
    $depth0 = $this->composer->post($this->thread, null, $this->student, 'Depth 0');
    $depth1 = $this->composer->post($this->thread, $depth0, $this->student, 'Depth 1');
    $depth2 = $this->composer->post($this->thread, $depth1, $this->student, 'Depth 2');

    expect($depth1->depth)->toBe(1);
    expect($depth2->depth)->toBe(2);
});

it('refuses a reply that would exceed depth 2', function () {
    $depth0 = $this->composer->post($this->thread, null, $this->student, 'Depth 0');
    $depth1 = $this->composer->post($this->thread, $depth0, $this->student, 'Depth 1');
    $depth2 = $this->composer->post($this->thread, $depth1, $this->student, 'Depth 2');

    expect(fn () => $this->composer->post($this->thread, $depth2, $this->student, 'Depth 3'))
        ->toThrow(ForumReplyDepthExceededException::class);
});
