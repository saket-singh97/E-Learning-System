<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Content\Models\Course;
use App\Domains\Operations\Filament\Resources\Enquiries\EnquiryResource;
use App\Domains\Operations\Filament\Resources\Enquiries\Pages\CreateEnquiry;
use App\Domains\Operations\Filament\Resources\Enquiries\Pages\EditEnquiry;
use App\Domains\Operations\Filament\Resources\Enquiries\Pages\ListEnquiries;
use App\Domains\Operations\Filament\Resources\Enquiries\RelationManagers\FollowUpsRelationManager;
use App\Domains\Operations\Models\Enquiry;
use App\Domains\Operations\Models\EnquiryFollowUp;
use App\Domains\Batches\Models\Enrolment;
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
});

it('creates an enquiry from the Admin panel', function () {
    $this->actingAs($this->admin)
        ->get(EnquiryResource::getUrl('create', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    Livewire::test(CreateEnquiry::class)
        ->fillForm([
            'name' => 'Priya Sharma',
            'email' => 'priya@example.com',
            'status' => 'new',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Enquiry::where('email', 'priya@example.com')->exists())->toBeTrue();
});

it('is also reachable from the Teach panel', function () {
    $this->actingAs($this->instructor)
        ->get(EnquiryResource::getUrl('create', ['subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();

    Livewire::test(CreateEnquiry::class)
        ->fillForm(['name' => 'Ravi Shah', 'email' => 'ravi@example.com', 'status' => 'new'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Enquiry::where('email', 'ravi@example.com')->exists())->toBeTrue();
});

it('lists enquiries for the current tenant only', function () {
    $mine = Enquiry::create(['name' => 'Mine', 'email' => 'mine@example.com', 'status' => 'new']);

    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    app(CurrentTenant::class)->set($otherTenant);
    $theirs = Enquiry::create(['name' => 'Theirs', 'email' => 'theirs@example.com', 'status' => 'new']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->actingAs($this->admin)
        ->get(EnquiryResource::getUrl('index', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    Livewire::test(ListEnquiries::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('logs a follow-up note, defaulting the logger to the current admin', function () {
    $enquiry = Enquiry::create(['name' => 'Priya Sharma', 'email' => 'priya@example.com', 'status' => 'new']);

    $this->actingAs($this->admin)
        ->get(EnquiryResource::getUrl('edit', ['record' => $enquiry, 'subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    Livewire::test(FollowUpsRelationManager::class, [
        'ownerRecord' => $enquiry,
        'pageClass' => EditEnquiry::class,
    ])->callTableAction('create', data: [
        'note' => 'Called, interested, will decide next week.',
        'next_follow_up_at' => now()->addWeek()->toDateTimeString(),
    ])->assertHasNoTableActionErrors();

    $followUp = EnquiryFollowUp::where('enquiry_id', $enquiry->id)->firstOrFail();
    expect($followUp->user_id)->toBe($this->admin->id);
    expect($followUp->note)->toBe('Called, interested, will decide next week.');
});

it('converts an enquiry to a real enrolment through the Filament action', function () {
    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch A',
        'slug' => 'batch-a',
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    $enquiry = Enquiry::create(['name' => 'Priya Sharma', 'email' => 'priya@example.com', 'status' => 'new']);

    $this->actingAs($this->admin)
        ->get(EnquiryResource::getUrl('index', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    Livewire::test(ListEnquiries::class)
        ->callTableAction('convertToEnrolment', $enquiry, data: ['batch_id' => $batch->id]);

    expect($enquiry->fresh()->status)->toBe('converted');
    $student = User::where('email', 'priya@example.com')->firstOrFail();
    expect(Enrolment::where('batch_id', $batch->id)->where('user_id', $student->id)->exists())->toBeTrue();
});
