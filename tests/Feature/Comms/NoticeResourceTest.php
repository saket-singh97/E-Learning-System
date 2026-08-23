<?php

use App\Domains\Comms\Filament\Resources\Notices\NoticeResource;
use App\Domains\Comms\Filament\Resources\Notices\Pages\CreateNotice;
use App\Domains\Comms\Filament\Resources\Notices\Pages\ListNotices;
use App\Domains\Comms\Models\Notice;
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

    $this->actingAs($this->admin)
        ->get(NoticeResource::getUrl('create', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();
});

it('creates a draft notice when published_at is left blank, authored by the current admin', function () {
    Livewire::test(CreateNotice::class)
        ->fillForm([
            'title' => 'Diwali holiday',
            'body' => 'The institute will be closed for Diwali.',
            'audience_roles' => ['student', 'parent'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $notice = Notice::where('title', 'Diwali holiday')->firstOrFail();
    expect($notice->isPublished())->toBeFalse();
    expect($notice->created_by_user_id)->toBe($this->admin->id);
    expect($notice->audience_roles)->toBe(['student', 'parent']);
});

it('publishes a draft notice via the publishNow action', function () {
    $notice = Notice::create([
        'title' => 'Fee deadline',
        'body' => 'Last date to pay is Friday.',
        'audience_roles' => ['student'],
        'created_by_user_id' => $this->admin->id,
    ]);

    Livewire::test(ListNotices::class)
        ->callTableAction('publishNow', $notice);

    expect($notice->fresh()->isPublished())->toBeTrue();
});

it('lists notices for the current tenant only', function () {
    $mine = Notice::create(['title' => 'Mine', 'body' => 'x', 'audience_roles' => ['student'], 'created_by_user_id' => $this->admin->id]);

    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    app(CurrentTenant::class)->set($otherTenant);
    $otherAdmin = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $theirs = Notice::create(['title' => 'Theirs', 'body' => 'x', 'audience_roles' => ['student'], 'created_by_user_id' => $otherAdmin->id]);
    app(CurrentTenant::class)->set($this->tenant);

    Livewire::test(ListNotices::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('is not reachable from the Teach panel', function () {
    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');

    $this->actingAs($instructor)
        ->get('http://physics.lms.test/teach/notices')
        ->assertNotFound();
});
