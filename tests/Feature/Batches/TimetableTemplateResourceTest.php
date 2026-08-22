<?php

use App\Domains\Batches\Filament\Resources\TimetableTemplates\Pages\CreateTimetableTemplate;
use App\Domains\Batches\Filament\Resources\TimetableTemplates\Pages\ListTimetableTemplates;
use App\Domains\Batches\Filament\Resources\TimetableTemplates\TimetableTemplateResource;
use App\Domains\Batches\Models\TimetableTemplate;
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
        ->get(TimetableTemplateResource::getUrl('create', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();
});

it('creates a timetable template through the admin panel', function () {
    Livewire::test(CreateTimetableTemplate::class)
        ->fillForm([
            'title' => 'Weekday Morning Slot',
            'day_of_week' => 1,
            'starts_at' => '09:00',
            'duration_minutes' => 90,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $template = TimetableTemplate::where('title', 'Weekday Morning Slot')->firstOrFail();
    expect($template->tenant_id)->toBe($this->tenant->id);
    expect($template->day_of_week)->toBe(1);
    expect($template->duration_minutes)->toBe(90);
});

it('lists templates for the current tenant only', function () {
    $mine = TimetableTemplate::create([
        'title' => 'Mine',
        'day_of_week' => 2,
        'starts_at' => '10:00:00',
        'duration_minutes' => 60,
    ]);

    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    app(CurrentTenant::class)->set($otherTenant);
    $theirs = TimetableTemplate::create([
        'title' => 'Theirs',
        'day_of_week' => 2,
        'starts_at' => '10:00:00',
        'duration_minutes' => 60,
    ]);
    app(CurrentTenant::class)->set($this->tenant);

    Livewire::test(ListTimetableTemplates::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('is not reachable from the Teach panel', function () {
    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');

    // Not registered on TeachPanelProvider at all (Admin-panel CRUD per
    // the plan) — getUrl(panel: 'teach') would throw RouteNotFoundException
    // since no such named route exists, so hit the raw path instead.
    $this->actingAs($instructor)
        ->get('http://physics.lms.test/teach/timetable-templates')
        ->assertNotFound();
});
