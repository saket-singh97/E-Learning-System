<?php

use App\Domains\Comms\Jobs\ExportCompletionRegister;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin->assignRole('institute_admin');
});

it('404s before the register has been generated', function () {
    $this->actingAs($this->admin)
        ->get('http://physics.lms.test/exports/completion-register')
        ->assertNotFound();
});

it('downloads the register once generated', function () {
    ExportCompletionRegister::dispatchSync($this->tenant->id);

    $this->actingAs($this->admin)
        ->get('http://physics.lms.test/exports/completion-register')
        ->assertOk()
        ->assertDownload();
});

it('forbids a non-admin from downloading', function () {
    ExportCompletionRegister::dispatchSync($this->tenant->id);
    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');

    $this->actingAs($instructor)
        ->get('http://physics.lms.test/exports/completion-register')
        ->assertForbidden();
});
