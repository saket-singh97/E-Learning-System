<?php

use App\Domains\Batches\Filament\Resources\Batches\BatchResource;
use App\Domains\Batches\Filament\Resources\Batches\Pages\CreateBatch;
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

it('rejects a duplicate slug within the same tenant with a form error, not a database crash', function () {
    Batch::create([
        'course_id' => $this->course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-batch',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'draft',
    ]);

    Livewire::test(CreateBatch::class)
        ->fillForm([
            'course_id' => $this->course->id,
            'title' => 'Batch',
            'slug' => 'morning-batch',
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-12-01',
            'self_enrol_mode' => 'manual_only',
            'status' => 'draft',
        ])
        ->call('create')
        ->assertHasFormErrors(['slug']);

    expect(Batch::where('slug', 'morning-batch')->count())->toBe(1);
});

it('lets a new batch reuse the slug of a soft-deleted one through the create form', function () {
    $deleted = Batch::create([
        'course_id' => $this->course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-batch',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'draft',
    ]);
    $deleted->delete();

    Livewire::test(CreateBatch::class)
        ->fillForm([
            'course_id' => $this->course->id,
            'title' => 'Batch',
            'slug' => 'morning-batch',
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-12-01',
            'self_enrol_mode' => 'manual_only',
            'status' => 'draft',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Batch::where('slug', 'morning-batch')->count())->toBe(1);
});
