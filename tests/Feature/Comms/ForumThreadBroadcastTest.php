<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Comms\Events\ForumPostCreated;
use App\Domains\Comms\Livewire\BatchForum;
use App\Domains\Comms\Livewire\GeneralForum;
use App\Domains\Comms\Models\ForumThread;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * S19 N14 pt.2 — real-time push for forum threads, both batch-scoped and
 * general. Same channel-auth-endpoint approach B0's own tests established
 * (`broadcasting.default` forced to `reverb` + `routes/channels.php`
 * re-required, since `BroadcastManager` only registers channels once, at
 * boot, against whatever driver was default then) plus B1's `Event::fake`
 * approach for verifying the broadcast itself fires on the right channel.
 */
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

    $this->enrolledStudent = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->enrolledStudent->assignRole('student');
    Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $this->enrolledStudent->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    $this->unrelatedTenantUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->unrelatedTenantUser->assignRole('student');

    config(['broadcasting.default' => 'reverb']);
    require base_path('routes/channels.php');
});

it('authorizes an actively enrolled student for their batch thread channel', function () {
    $thread = ForumThread::create(['batch_id' => $this->batch->id, 'title' => 'Week 1', 'created_by_user_id' => $this->instructor->id]);

    $this->actingAs($this->enrolledStudent)
        ->postJson('/broadcasting/auth', ['channel_name' => 'private-forum-thread.'.$thread->id, 'socket_id' => '1234.1234'])
        ->assertOk();
});

it('refuses a tenant user with no enrolment for a batch thread channel', function () {
    $thread = ForumThread::create(['batch_id' => $this->batch->id, 'title' => 'Week 1', 'created_by_user_id' => $this->instructor->id]);

    $this->actingAs($this->unrelatedTenantUser)
        ->postJson('/broadcasting/auth', ['channel_name' => 'private-forum-thread.'.$thread->id, 'socket_id' => '1234.1234'])
        ->assertForbidden();
});

it('authorizes the batchs own instructor without an enrolment row', function () {
    $thread = ForumThread::create(['batch_id' => $this->batch->id, 'title' => 'Week 1', 'created_by_user_id' => $this->instructor->id]);

    $this->actingAs($this->instructor)
        ->postJson('/broadcasting/auth', ['channel_name' => 'private-forum-thread.'.$thread->id, 'socket_id' => '1234.1234'])
        ->assertOk();
});

it('authorizes any tenant user for a general thread channel', function () {
    $thread = ForumThread::create(['batch_id' => null, 'title' => 'General topic', 'created_by_user_id' => $this->instructor->id]);

    $this->actingAs($this->unrelatedTenantUser)
        ->postJson('/broadcasting/auth', ['channel_name' => 'private-forum-thread.'.$thread->id, 'socket_id' => '1234.1234'])
        ->assertOk();
});

it('refuses a different tenants user for a general thread channel', function () {
    $thread = ForumThread::create(['batch_id' => null, 'title' => 'General topic', 'created_by_user_id' => $this->instructor->id]);

    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    $otherTenantUser = User::factory()->create(['tenant_id' => $otherTenant->id]);

    $this->actingAs($otherTenantUser)
        ->postJson('/broadcasting/auth', ['channel_name' => 'private-forum-thread.'.$thread->id, 'socket_id' => '1234.1234'])
        ->assertForbidden();
});

it('broadcasts a new batch thread post on its own forum-thread channel', function () {
    Event::fake([ForumPostCreated::class]);

    Livewire::actingAs($this->enrolledStudent)
        ->test(BatchForum::class, ['batch' => $this->batch])
        ->set('newThreadTitle', 'Week 1')
        ->set('newThreadBody', 'Hello')
        ->call('createThread');

    $thread = ForumThread::where('batch_id', $this->batch->id)->firstOrFail();

    Event::assertDispatched(ForumPostCreated::class, function (ForumPostCreated $event) use ($thread) {
        return (string) $event->broadcastOn()[0] === 'private-forum-thread.'.$thread->id
            && $event->broadcastAs() === 'post.created'
            && $event->broadcastWith()['body'] === 'Hello';
    });
});

it('broadcasts a new general thread post on its own forum-thread channel', function () {
    Event::fake([ForumPostCreated::class]);

    Livewire::actingAs($this->unrelatedTenantUser)
        ->test(GeneralForum::class)
        ->set('newThreadTitle', 'General topic')
        ->set('newThreadBody', 'Hi all')
        ->call('createThread');

    $thread = ForumThread::whereNull('batch_id')->firstOrFail();

    Event::assertDispatched(ForumPostCreated::class, fn (ForumPostCreated $event) => (string) $event->broadcastOn()[0] === 'private-forum-thread.'.$thread->id);
});
