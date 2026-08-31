<?php

use App\Domains\Batches\Filament\Resources\Batches\BatchResource;
use App\Domains\Batches\Filament\Resources\Batches\Pages\CreateBatch;
use App\Domains\Batches\Filament\Resources\Batches\Pages\EditBatch;
use App\Domains\Batches\Filament\Resources\Batches\Pages\ListBatches;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchGeofence;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\TimetableTemplate;
use App\Domains\Batches\Services\AutoSchedulerService;
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

    $this->course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);

    $this->actingAs($this->admin)
        ->get(BatchResource::getUrl('create', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();
});

it('creates a batch through the admin panel', function () {
    Livewire::test(CreateBatch::class)
        ->fillForm([
            'course_id' => $this->course->id,
            'title' => 'Morning Batch',
            'slug' => 'morning-batch',
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-12-01',
            'seat_limit' => 10,
            'self_enrol_mode' => 'self_enrol_direct',
            'status' => 'published',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $batch = Batch::where('slug', 'morning-batch')->first();
    expect($batch)->not->toBeNull();
    expect($batch->course_id)->toBe($this->course->id);
    expect($batch->seat_limit)->toBe(10);
    expect($batch->tenant_id)->toBe($this->tenant->id);
});

it('rejects an end date before the start date', function () {
    Livewire::test(CreateBatch::class)
        ->fillForm([
            'course_id' => $this->course->id,
            'title' => 'Broken Batch',
            'slug' => 'broken-batch',
            'starts_on' => '2026-12-01',
            'ends_on' => '2026-09-01',
            'self_enrol_mode' => 'manual_only',
            'status' => 'draft',
        ])
        ->call('create')
        ->assertHasFormErrors(['starts_on']);

    expect(Batch::where('slug', 'broken-batch')->count())->toBe(0);
});

it('requires a price when the batch is marked paid', function () {
    Livewire::test(CreateBatch::class)
        ->fillForm([
            'course_id' => $this->course->id,
            'title' => 'Paid Batch',
            'slug' => 'paid-batch',
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-12-01',
            'is_paid' => true,
            'price' => null,
            'self_enrol_mode' => 'manual_only',
            'status' => 'draft',
        ])
        ->call('create')
        ->assertHasFormErrors(['price']);
});

it('creates a paid batch with an instalment plan that sums to 100', function () {
    Livewire::test(CreateBatch::class)
        ->fillForm([
            'course_id' => $this->course->id,
            'title' => 'Instalment Batch',
            'slug' => 'instalment-batch',
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-12-01',
            'is_paid' => true,
            'price' => 1000,
            'instalment_plan' => [
                ['percent' => 50, 'days_after_enrolment' => 0],
                ['percent' => 50, 'days_after_enrolment' => 30],
            ],
            'self_enrol_mode' => 'self_enrol_direct',
            'status' => 'published',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $batch = Batch::where('slug', 'instalment-batch')->sole();

    expect($batch->instalment_plan)->toHaveCount(2)
        ->and((float) $batch->instalment_plan[0]['percent'])->toBe(50.0);
});

it('rejects an instalment plan whose percentages do not sum to 100', function () {
    Livewire::test(CreateBatch::class)
        ->fillForm([
            'course_id' => $this->course->id,
            'title' => 'Broken Instalment Batch',
            'slug' => 'broken-instalment-batch',
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-12-01',
            'is_paid' => true,
            'price' => 1000,
            'instalment_plan' => [
                ['percent' => 50, 'days_after_enrolment' => 0],
                ['percent' => 40, 'days_after_enrolment' => 30],
            ],
            'self_enrol_mode' => 'self_enrol_direct',
            'status' => 'published',
        ])
        ->call('create')
        ->assertHasFormErrors(['instalment_plan']);

    expect(Batch::where('slug', 'broken-instalment-batch')->count())->toBe(0);
});

it('lets an admin assign an already-active instructor to a batch, not just via invitation accept', function () {
    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');

    Livewire::test(CreateBatch::class)
        ->fillForm([
            'course_id' => $this->course->id,
            'title' => 'Assigned Batch',
            'slug' => 'assigned-batch',
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-12-01',
            'instructor_id' => $instructor->id,
            'self_enrol_mode' => 'manual_only',
            'status' => 'published',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $batch = Batch::where('slug', 'assigned-batch')->sole();
    expect($batch->instructor_id)->toBe($instructor->id);
});

it('lets an admin reassign an existing batchs instructor from the edit form', function () {
    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');
    $batch = Batch::create([
        'course_id' => $this->course->id,
        'title' => 'Reassign Batch',
        'slug' => 'reassign-batch',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'draft',
    ]);

    Livewire::test(EditBatch::class, ['record' => $batch->id])
        ->fillForm(['instructor_id' => $instructor->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($batch->refresh()->instructor_id)->toBe($instructor->id);
});

it('edits an existing batch', function () {
    $batch = Batch::create([
        'course_id' => $this->course->id,
        'title' => 'Evening Batch',
        'slug' => 'evening-batch',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'draft',
    ]);

    $this->actingAs($this->admin)
        ->get("http://physics.lms.test/admin/batches/{$batch->id}/edit")
        ->assertOk();

    Livewire::test(EditBatch::class, ['record' => $batch->id])
        ->fillForm(['title' => 'Evening Batch (Updated)'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($batch->refresh()->title)->toBe('Evening Batch (Updated)');
});

it('lists batches for the current tenant', function () {
    Batch::create([
        'course_id' => $this->course->id,
        'title' => 'Listed Batch',
        'slug' => 'listed-batch',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'draft',
    ]);

    Livewire::test(ListBatches::class)
        ->assertCanSeeTableRecords(Batch::all());
});

it('soft-deletes a batch and restores it from the table actions', function () {
    $batch = Batch::create([
        'course_id' => $this->course->id,
        'title' => 'Cancelled Batch',
        'slug' => 'cancelled-batch',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'draft',
    ]);

    Livewire::test(ListBatches::class)
        ->callTableAction('delete', $batch);

    expect($batch->refresh()->trashed())->toBeTrue();

    Livewire::test(ListBatches::class)
        ->callTableAction('restore', $batch);

    expect($batch->refresh()->trashed())->toBeFalse();
});

// S16 B2 (H11 pt.1) — geofence table action.
it('sets a geofence for a batch from the admin table action', function () {
    $batch = Batch::create([
        'course_id' => $this->course->id,
        'title' => 'Geo Batch',
        'slug' => 'geo-batch',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'draft',
    ]);

    Livewire::test(ListBatches::class)
        ->callTableAction('geofence', $batch, data: [
            'latitude' => 12.9752,
            'longitude' => 77.6068,
            'radius_meters' => 150,
        ]);

    $geofence = BatchGeofence::where('batch_id', $batch->id)->sole();
    expect($geofence->radius_meters)->toBe(150);
    expect($geofence->tenant_id)->toBe($this->tenant->id);
});

it('updates an existing geofence rather than creating a duplicate', function () {
    $batch = Batch::create([
        'course_id' => $this->course->id,
        'title' => 'Geo Batch 2',
        'slug' => 'geo-batch-2',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'draft',
    ]);
    BatchGeofence::create(['batch_id' => $batch->id, 'latitude' => 1, 'longitude' => 1, 'radius_meters' => 50]);

    Livewire::test(ListBatches::class)
        ->callTableAction('geofence', $batch, data: [
            'latitude' => 12.9752,
            'longitude' => 77.6068,
            'radius_meters' => 200,
        ]);

    expect(BatchGeofence::where('batch_id', $batch->id)->count())->toBe(1);
    expect(BatchGeofence::where('batch_id', $batch->id)->sole()->radius_meters)->toBe(200);
});

// S24 B5 (U22 pt.2) — auto-schedule review/accept table action.
it('accepts a proposed auto-schedule slot and creates a real BatchSession row', function () {
    TimetableTemplate::create(['title' => 'Mon 4pm', 'day_of_week' => 1, 'starts_at' => '16:00:00', 'duration_minutes' => 60]);

    $batch = Batch::create([
        'course_id' => $this->course->id,
        'title' => 'Auto Batch',
        'slug' => 'auto-batch',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'draft',
    ]);

    $proposal = app(AutoSchedulerService::class)->propose($batch, 1)->first();

    Livewire::test(ListBatches::class)
        ->callTableAction('autoSchedule', $batch, data: [
            'session_count' => 1,
            'candidates' => [
                [
                    'accept' => true,
                    'when' => 'irrelevant display text',
                    'starts_at' => $proposal['starts_at']->format('Y-m-d H:i:s'),
                    'duration_minutes' => $proposal['duration_minutes'],
                ],
            ],
        ]);

    $session = BatchSession::where('batch_id', $batch->id)->sole();
    expect($session->tenant_id)->toBe($this->tenant->id)
        ->and($session->duration_minutes)->toBe(60)
        ->and($session->position)->toBe(1)
        ->and($session->lesson_id)->toBeNull()
        ->and($session->starts_at->format('Y-m-d H:i:s'))->toBe($proposal['starts_at']->format('Y-m-d H:i:s'));
});

it('writes no BatchSession when every proposed auto-schedule slot is rejected', function () {
    TimetableTemplate::create(['title' => 'Mon 4pm', 'day_of_week' => 1, 'starts_at' => '16:00:00', 'duration_minutes' => 60]);

    $batch = Batch::create([
        'course_id' => $this->course->id,
        'title' => 'Skipped Batch',
        'slug' => 'skipped-batch',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'draft',
    ]);

    $proposal = app(AutoSchedulerService::class)->propose($batch, 1)->first();

    Livewire::test(ListBatches::class)
        ->callTableAction('autoSchedule', $batch, data: [
            'session_count' => 1,
            'candidates' => [
                [
                    'accept' => false,
                    'when' => 'irrelevant display text',
                    'starts_at' => $proposal['starts_at']->format('Y-m-d H:i:s'),
                    'duration_minutes' => $proposal['duration_minutes'],
                ],
            ],
        ]);

    expect(BatchSession::where('batch_id', $batch->id)->count())->toBe(0);
});
