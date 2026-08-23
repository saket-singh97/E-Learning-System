<?php

use App\Domains\Comms\Livewire\NoticeBoard;
use App\Domains\Comms\Models\Notice;
use App\Domains\Comms\Models\NoticeReadReceipt;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin->assignRole('institute_admin');

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');
});

it('shows a published notice targeted at the viewers own role', function () {
    Notice::create([
        'title' => 'Holiday notice',
        'body' => 'Closed Friday.',
        'audience_roles' => ['student'],
        'published_at' => now(),
        'created_by_user_id' => $this->admin->id,
    ]);

    Livewire::actingAs($this->student)
        ->test(NoticeBoard::class)
        ->assertSee('Holiday notice');
});

it('hides a notice targeted at a different role', function () {
    Notice::create([
        'title' => 'Staff meeting',
        'body' => 'Friday 5pm.',
        'audience_roles' => ['instructor'],
        'published_at' => now(),
        'created_by_user_id' => $this->admin->id,
    ]);

    Livewire::actingAs($this->student)
        ->test(NoticeBoard::class)
        ->assertDontSee('Staff meeting');
});

it('hides an unpublished draft notice', function () {
    Notice::create([
        'title' => 'Draft notice',
        'body' => 'Not ready yet.',
        'audience_roles' => ['student'],
        'published_at' => null,
        'created_by_user_id' => $this->admin->id,
    ]);

    Livewire::actingAs($this->student)
        ->test(NoticeBoard::class)
        ->assertDontSee('Draft notice');
});

it('records a read receipt once, idempotently, when marked as read', function () {
    $notice = Notice::create([
        'title' => 'Holiday notice',
        'body' => 'Closed Friday.',
        'audience_roles' => ['student'],
        'published_at' => now(),
        'created_by_user_id' => $this->admin->id,
    ]);

    Livewire::actingAs($this->student)
        ->test(NoticeBoard::class)
        ->call('markAsRead', $notice->id)
        ->call('markAsRead', $notice->id);

    expect(NoticeReadReceipt::where('notice_id', $notice->id)->where('user_id', $this->student->id)->count())->toBe(1);
});

it('shows nothing for a guest', function () {
    Notice::create([
        'title' => 'Holiday notice',
        'body' => 'Closed Friday.',
        'audience_roles' => ['student'],
        'published_at' => now(),
        'created_by_user_id' => $this->admin->id,
    ]);

    Livewire::test(NoticeBoard::class)
        ->assertDontSee('Holiday notice');
});
