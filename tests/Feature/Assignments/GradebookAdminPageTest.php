<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Filament\Admin\Pages\Gradebook;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lets an institute admin see the tenant-wide gradebook', function () {
    $this->seed(RoleSeeder::class);

    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);

    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');
    Enrolment::create(['batch_id' => $batch->id, 'user_id' => $student->id, 'status' => 'active', 'source' => 'manual_add', 'requested_at' => now()]);

    $this->actingAs($admin)
        ->get(Gradebook::getUrl(['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    Livewire::test(Gradebook::class)
        ->assertSee($student->name)
        ->assertSee($course->title);
});
