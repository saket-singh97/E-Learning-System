<?php

use App\Domains\Batches\Filament\Resources\Batches\BatchResource;
use App\Domains\Batches\Filament\Resources\Batches\Pages\EditBatch;
use App\Domains\Batches\Filament\Resources\Batches\RelationManagers\FeedbackRelationManager;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchFeedback;
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

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $this->batch = Batch::create([
        'course_id' => $course->id,
        'instructor_id' => $this->instructor->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-batch',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);

    $studentA = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $studentB = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->feedbackA = BatchFeedback::create(['batch_id' => $this->batch->id, 'user_id' => $studentA->id, 'rating' => 4, 'comment' => 'Good pace']);
    $this->feedbackB = BatchFeedback::create(['batch_id' => $this->batch->id, 'user_id' => $studentB->id, 'rating' => 2, 'comment' => null]);
});

it('shows the feedback list and average rating to an institute_admin', function () {
    $this->actingAs($this->admin)
        ->get(BatchResource::getUrl('edit', ['record' => $this->batch, 'subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    Livewire::test(FeedbackRelationManager::class, [
        'ownerRecord' => $this->batch,
        'pageClass' => EditBatch::class,
    ])->assertCanSeeTableRecords([$this->feedbackA, $this->feedbackB])
        ->assertSee('Good pace');
});

it('shows the feedback list to the batchs own assigned instructor via the Teach panel', function () {
    $this->actingAs($this->instructor)
        ->get(BatchResource::getUrl('edit', ['record' => $this->batch, 'subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();

    Livewire::test(FeedbackRelationManager::class, [
        'ownerRecord' => $this->batch,
        'pageClass' => EditBatch::class,
    ])->assertCanSeeTableRecords([$this->feedbackA, $this->feedbackB]);
});
