<?php

use App\Domains\Batches\Filament\Resources\Batches\BatchResource;
use App\Domains\Batches\Filament\Resources\Batches\Pages\CreateBatch;
use App\Domains\Batches\Filament\Resources\Batches\Pages\EditBatch;
use App\Domains\Batches\Filament\Resources\Batches\Pages\ListBatches;
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
