<?php

use App\Domains\Batches\Filament\Resources\Batches\BatchResource;
use App\Domains\Batches\Filament\Resources\Batches\Pages\EditBatch;
use App\Domains\Batches\Filament\Resources\Batches\RelationManagers\BatchSessionsRelationManager;
use App\Domains\Batches\Models\Batch;
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

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);

    $this->batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-batch',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'draft',
    ]);

    $this->actingAs($this->admin)
        ->get(BatchResource::getUrl('edit', ['record' => $this->batch, 'subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();
});

it('assigns sessions created without dragging a strictly increasing position, not the column default of 0', function () {
    $relationManager = Livewire::test(BatchSessionsRelationManager::class, [
        'ownerRecord' => $this->batch,
        'pageClass' => EditBatch::class,
    ]);

    $relationManager->callTableAction('create', data: [
        'title' => 'Session 1',
        'starts_at' => '2026-09-08 10:00:00',
        'duration_minutes' => 60,
    ])->assertHasNoTableActionErrors();

    $relationManager->callTableAction('create', data: [
        'title' => 'Session 2',
        'starts_at' => '2026-09-15 10:00:00',
        'duration_minutes' => 90,
    ])->assertHasNoTableActionErrors();

    $positions = $this->batch->fresh()->sessions()->orderBy('id')->pluck('position')->all();

    expect($positions)->toBe([1, 2])
        ->and($this->batch->fresh()->sessions->pluck('title')->all())->toBe(['Session 1', 'Session 2']);
});

it('edits and deletes a session via the relation manager', function () {
    $relationManager = Livewire::test(BatchSessionsRelationManager::class, [
        'ownerRecord' => $this->batch,
        'pageClass' => EditBatch::class,
    ]);

    $relationManager->callTableAction('create', data: [
        'title' => 'Session 1',
        'starts_at' => '2026-09-08 10:00:00',
        'duration_minutes' => 60,
    ])->assertHasNoTableActionErrors();

    $session = $this->batch->fresh()->sessions()->first();

    $relationManager->callTableAction('edit', $session, data: [
        'title' => 'Session 1 (Rescheduled)',
        'starts_at' => '2026-09-09 10:00:00',
        'duration_minutes' => 60,
    ])->assertHasNoTableActionErrors();

    expect($session->fresh()->title)->toBe('Session 1 (Rescheduled)');

    $relationManager->callTableAction('delete', $session);

    expect($this->batch->fresh()->sessions()->count())->toBe(0);
});

it('rejects a session duration shorter than 15 minutes', function () {
    Livewire::test(BatchSessionsRelationManager::class, [
        'ownerRecord' => $this->batch,
        'pageClass' => EditBatch::class,
    ])->callTableAction('create', data: [
        'title' => 'Too short',
        'starts_at' => '2026-09-08 10:00:00',
        'duration_minutes' => 10,
    ])->assertHasTableActionErrors(['duration_minutes']);

    expect($this->batch->fresh()->sessions()->count())->toBe(0);
});

it('rejects a session duration longer than 480 minutes', function () {
    Livewire::test(BatchSessionsRelationManager::class, [
        'ownerRecord' => $this->batch,
        'pageClass' => EditBatch::class,
    ])->callTableAction('create', data: [
        'title' => 'Too long',
        'starts_at' => '2026-09-08 10:00:00',
        'duration_minutes' => 481,
    ])->assertHasTableActionErrors(['duration_minutes']);

    expect($this->batch->fresh()->sessions()->count())->toBe(0);
});
