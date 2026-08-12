<?php

use App\Domains\Comms\Filament\Resources\ParentGuardians\ParentGuardianResource;
use App\Domains\Comms\Filament\Resources\ParentGuardians\Pages\CreateParentGuardian;
use App\Domains\Comms\Filament\Resources\ParentGuardians\Pages\EditParentGuardian;
use App\Domains\Comms\Models\ParentGuardianLink;
use App\Domains\Tenancy\Models\RoleAssignmentAudit;
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

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');

    $this->actingAs($this->admin)
        ->get(ParentGuardianResource::getUrl('create', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();
});

it('creates a parent and links them to a student in one action', function () {
    Livewire::test(CreateParentGuardian::class)
        ->fillForm([
            'name' => "Riya's Father",
            'email' => 'father@example.com',
            'phone' => '+919876543210',
            'password' => 'password123',
            'links' => [
                ['student_user_id' => $this->student->id, 'relationship' => 'father'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $parent = User::where('email', 'father@example.com')->sole();

    expect($parent->hasRole('parent'))->toBeTrue()
        ->and($parent->phone)->toBe('+919876543210');

    $link = ParentGuardianLink::sole();
    expect($link->parent_user_id)->toBe($parent->id)
        ->and($link->student_user_id)->toBe($this->student->id)
        ->and($link->relationship)->toBe('father');

    $audit = RoleAssignmentAudit::withoutGlobalScope('tenant')->latest('id')->first();
    expect($audit->allowed)->toBeTrue()
        ->and($audit->attempted_role)->toBe('parent');
});

it('links a parent to more than one student in the same action', function () {
    $secondStudent = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $secondStudent->assignRole('student');

    Livewire::test(CreateParentGuardian::class)
        ->fillForm([
            'name' => 'Two Kids Parent',
            'email' => 'twokids@example.com',
            'phone' => '+919876500000',
            'password' => 'password123',
            'links' => [
                ['student_user_id' => $this->student->id, 'relationship' => 'father'],
                ['student_user_id' => $secondStudent->id, 'relationship' => 'father'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $parent = User::where('email', 'twokids@example.com')->sole();

    expect(ParentGuardianLink::where('parent_user_id', $parent->id)->count())->toBe(2);
});

it('rejects a student_user_id belonging to a different tenant, even if directly submitted', function () {
    // The student_user_id Select's options() closure is tenant-scoped,
    // and Filament validates a submitted value against that same
    // closure server-side — fillForm() bypasses the rendered dropdown
    // the same way a tampered wire:model update would, and Filament's
    // own validation still catches it (S8 B8 review: an initial pass
    // assumed this needed an extra server-side check in this resource;
    // a failing test proved that assumption wrong before any code was
    // added on top of Filament's own guarantee).
    $otherTenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($otherTenant);
    $otherStudent = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherStudent->assignRole('student');
    app(CurrentTenant::class)->set($this->tenant);

    Livewire::test(CreateParentGuardian::class)
        ->fillForm([
            'name' => 'Sneaky Parent',
            'email' => 'sneaky@example.com',
            'phone' => '+919876500099',
            'password' => 'password123',
            'links' => [
                ['student_user_id' => $otherStudent->id, 'relationship' => 'father'],
            ],
        ])
        ->call('create')
        ->assertHasErrors();

    expect(User::where('email', 'sneaky@example.com')->exists())->toBeFalse()
        ->and(ParentGuardianLink::count())->toBe(0);
});

it('requires at least one linked student', function () {
    Livewire::test(CreateParentGuardian::class)
        ->fillForm([
            'name' => 'No Kids',
            'email' => 'nokids@example.com',
            'phone' => '+919876500001',
            'password' => 'password123',
            'links' => [],
        ])
        ->call('create')
        ->assertHasFormErrors(['links']);
});

it('edits a parent, adding and removing linked students', function () {
    $parent = User::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Parent',
        'email' => 'parent@example.com',
        'phone' => '+919876500002',
        'password' => 'password123',
    ]);
    $parent->assignRole('parent');
    ParentGuardianLink::create([
        'parent_user_id' => $parent->id,
        'student_user_id' => $this->student->id,
        'relationship' => 'father',
    ]);

    $secondStudent = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $secondStudent->assignRole('student');

    Livewire::test(EditParentGuardian::class, ['record' => $parent->id])
        ->fillForm([
            'links' => [
                ['student_user_id' => $secondStudent->id, 'relationship' => 'mother'],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $links = ParentGuardianLink::where('parent_user_id', $parent->id)->get();

    expect($links)->toHaveCount(1)
        ->and($links->first()->student_user_id)->toBe($secondStudent->id)
        ->and($links->first()->relationship)->toBe('mother');
});

it('rejects a student_user_id belonging to a different tenant on edit too', function () {
    $parent = User::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Parent',
        'email' => 'parent2@example.com',
        'phone' => '+919876500003',
        'password' => 'password123',
    ]);
    $parent->assignRole('parent');
    ParentGuardianLink::create([
        'parent_user_id' => $parent->id,
        'student_user_id' => $this->student->id,
        'relationship' => 'father',
    ]);

    $otherTenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($otherTenant);
    $otherStudent = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherStudent->assignRole('student');
    app(CurrentTenant::class)->set($this->tenant);

    Livewire::test(EditParentGuardian::class, ['record' => $parent->id])
        ->fillForm([
            'links' => [
                ['student_user_id' => $otherStudent->id, 'relationship' => 'father'],
            ],
        ])
        ->call('save')
        ->assertHasErrors();

    // Rejected before handleRecordUpdate() ever ran — the original link
    // to $this->student is untouched, not wiped.
    expect(ParentGuardianLink::where('parent_user_id', $parent->id)->count())->toBe(1)
        ->and(ParentGuardianLink::where('parent_user_id', $parent->id)->first()->student_user_id)->toBe($this->student->id);
});
