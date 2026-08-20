<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Content\Filament\Resources\Programs\Pages\CreateProgram;
use App\Domains\Content\Filament\Resources\Programs\ProgramResource;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\Program;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function programBatch(string $slug, string $status = 'published'): Batch
{
    $course = Course::create(['title' => 'Course', 'slug' => 'course-'.uniqid(), 'status' => 'published']);

    return Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch',
        'slug' => $slug,
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => $status,
    ]);
}

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);
});

it('bundles multiple batches and counts them', function () {
    $program = Program::create(['title' => 'Bundle', 'slug' => 'bundle', 'price' => 999, 'status' => 'draft']);
    $batchOne = programBatch('batch-one');
    $batchTwo = programBatch('batch-two');

    $program->batches()->attach([$batchOne->id, $batchTwo->id]);

    expect($program->batches()->count())->toBe(2);
});

it('enforces slug uniqueness per tenant, reusable across tenants', function () {
    Program::create(['title' => 'Bundle', 'slug' => 'bundle', 'price' => 999, 'status' => 'draft']);

    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    app(CurrentTenant::class)->set($otherTenant);

    expect(Program::create(['title' => 'Bundle', 'slug' => 'bundle', 'price' => 999, 'status' => 'draft']))
        ->not->toBeNull();
});

it('soft-deletes and restores without losing its batch bundle', function () {
    $program = Program::create(['title' => 'Bundle', 'slug' => 'bundle', 'price' => 999, 'status' => 'draft']);
    $batch = programBatch('batch-one');
    $program->batches()->attach($batch);

    $program->delete();
    expect(Program::find($program->id))->toBeNull();

    $program->restore();
    expect($program->fresh()->batches()->count())->toBe(1);
});

it('creates a program with selected published batches through the Admin panel form', function () {
    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $admin->assignRole('institute_admin');

    $publishedBatch = programBatch('batch-published', 'published');
    $draftBatch = programBatch('batch-draft', 'draft');

    $this->actingAs($admin)
        ->get(ProgramResource::getUrl('create', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    Livewire::actingAs($admin)
        ->test(CreateProgram::class)
        ->fillForm([
            'title' => 'Exam Prep Bundle',
            'slug' => 'exam-prep-bundle',
            'price' => 1999,
            'status' => 'published',
            'batches' => [$publishedBatch->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $program = Program::where('slug', 'exam-prep-bundle')->firstOrFail();
    expect($program->batches()->pluck('batches.id'))->toEqual(collect([$publishedBatch->id]));
    expect($program->batches()->pluck('batches.id'))->not->toContain($draftBatch->id);
});
