<?php

use App\Domains\Comms\Livewire\GeneralForum;
use App\Domains\Comms\Models\ForumThread;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * S19 N14 pt.1 — institute-wide forum (`batch_id IS NULL`, B2). No
 * enrolment/role gate, unlike `BatchForum` — any authenticated tenant user
 * can read and post, so these tests focus on the two things that actually
 * differ from `BatchForum`: tenant-wide visibility (not batch-scoped) and
 * cross-tenant isolation.
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->viewer = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

it('is visible to any authenticated tenant user, not just batch-enrolled ones', function () {
    Livewire::actingAs($this->viewer)
        ->test(GeneralForum::class)
        ->set('newThreadTitle', 'Welcome')
        ->set('newThreadBody', 'Hello everyone')
        ->call('createThread')
        ->assertSee('Hello everyone')
        ->call('closeThread')
        ->assertSee('Welcome');

    $thread = ForumThread::whereNull('batch_id')->first();
    expect($thread)->not->toBeNull();
    expect($thread->title)->toBe('Welcome');

    $otherViewer = User::factory()->create(['tenant_id' => $this->tenant->id]);

    Livewire::actingAs($otherViewer)
        ->test(GeneralForum::class)
        ->assertSee('Welcome');
});

it('never shows a guest any thread or post content', function () {
    ForumThread::create(['batch_id' => null, 'title' => 'Secret topic', 'created_by_user_id' => $this->viewer->id]);

    Livewire::test(GeneralForum::class)
        ->assertDontSee('Secret topic');
});

it('never leaks a general thread from another tenant', function () {
    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id]);

    app(CurrentTenant::class)->set($otherTenant);
    ForumThread::create(['batch_id' => null, 'title' => 'Chemistry only', 'created_by_user_id' => $otherUser->id]);

    app(CurrentTenant::class)->set($this->tenant);

    Livewire::actingAs($this->viewer)
        ->test(GeneralForum::class)
        ->assertDontSee('Chemistry only');
});

it('keeps the depth-limited reply path shared with BatchForum working', function () {
    Livewire::actingAs($this->viewer)
        ->test(GeneralForum::class)
        ->set('newThreadTitle', 'Topic')
        ->set('newThreadBody', 'Root post')
        ->call('createThread');

    $thread = ForumThread::whereNull('batch_id')->firstOrFail();
    $rootPost = $thread->posts()->firstOrFail();

    Livewire::actingAs($this->viewer)
        ->test(GeneralForum::class)
        ->call('openThread', $thread->id)
        ->set("replyBodies.{$rootPost->id}", 'A reply')
        ->call('reply', $rootPost->id)
        ->assertSee('A reply');
});
