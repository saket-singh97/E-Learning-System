<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Comms\Jobs\ExportEnrolmentRegister;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('writes a CSV register of every enrolment for the tenant', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch A',
        'slug' => 'batch-a',
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    $student = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Asha Kumar', 'email' => 'asha@example.com']);
    Enrolment::create([
        'batch_id' => $batch->id,
        'user_id' => $student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
        'decided_at' => now(),
    ]);

    ExportEnrolmentRegister::dispatchSync($tenant->id);

    $path = ExportEnrolmentRegister::pathFor($tenant->id);
    expect(Storage::disk('local')->exists($path))->toBeTrue();

    $csv = Storage::disk('local')->get($path);
    expect($csv)->toContain('Asha Kumar');
    expect($csv)->toContain('asha@example.com');
    expect($csv)->toContain('Batch A');
    expect($csv)->toContain('active');
});
