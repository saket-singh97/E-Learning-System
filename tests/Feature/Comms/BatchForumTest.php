<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Comms\Livewire\BatchForum;
use App\Domains\Comms\Models\ForumThread;
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

    $this->instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->instructor->assignRole('instructor');

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $this->batch = Batch::create([
        'course_id' => $course->id,
        'instructor_id' => $this->instructor->id,
        'title' => 'Batch A',
        'slug' => 'batch-a',
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');
    Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $this->student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);
});

it('lets an actively enrolled student start a thread', function () {
    Livewire::actingAs($this->student)
        ->test(BatchForum::class, ['batch' => $this->batch])
        ->set('newThreadTitle', 'Week 1')
        ->set('newThreadBody', 'What did everyone think?')
        ->call('createThread')
        ->assertSee('What did everyone think?');

    expect(ForumThread::where('batch_id', $this->batch->id)->where('title', 'Week 1')->exists())->toBeTrue();
});

it('lets the batchs own assigned instructor start a thread without an enrolment row', function () {
    Livewire::actingAs($this->instructor)
        ->test(BatchForum::class, ['batch' => $this->batch])
        ->set('newThreadTitle', 'Announcement')
        ->set('newThreadBody', 'Class moved to room 2')
        ->call('createThread');

    expect(ForumThread::where('batch_id', $this->batch->id)->where('title', 'Announcement')->exists())->toBeTrue();
});

it('no-ops for a student with no active enrolment in this batch', function () {
    $outsider = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $outsider->assignRole('student');

    Livewire::actingAs($outsider)
        ->test(BatchForum::class, ['batch' => $this->batch])
        ->set('newThreadTitle', 'Week 1')
        ->set('newThreadBody', 'Trying to post')
        ->call('createThread');

    expect(ForumThread::where('batch_id', $this->batch->id)->count())->toBe(0);
});

it('no-ops for a guest', function () {
    Livewire::test(BatchForum::class, ['batch' => $this->batch])
        ->set('newThreadTitle', 'Week 1')
        ->set('newThreadBody', 'Trying to post')
        ->call('createThread');

    expect(ForumThread::where('batch_id', $this->batch->id)->count())->toBe(0);
});

it('nests replies to depth 2 and refuses a depth-3 attempt with a visible error', function () {
    $component = Livewire::actingAs($this->student)
        ->test(BatchForum::class, ['batch' => $this->batch])
        ->set('newThreadTitle', 'Week 1')
        ->set('newThreadBody', 'Depth 0')
        ->call('createThread');

    $depth0 = ForumThread::where('batch_id', $this->batch->id)->firstOrFail()->posts()->firstOrFail();

    $component->set('replyBodies.'.$depth0->id, 'Depth 1')->call('reply', $depth0->id);
    $depth1 = $depth0->replies()->firstOrFail();

    $component->set('replyBodies.'.$depth1->id, 'Depth 2')->call('reply', $depth1->id);
    $depth2 = $depth1->replies()->firstOrFail();
    expect($depth2->depth)->toBe(2);

    $component->set('replyBodies.'.$depth2->id, 'Depth 3')
        ->call('reply', $depth2->id)
        ->assertHasErrors(["replyBodies.{$depth2->id}"]);

    expect($depth2->replies()->count())->toBe(0);
});
