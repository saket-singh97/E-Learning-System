<?php

use App\Domains\Comms\Models\ParentGuardianLink;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
});

it('links a parent user to a student user', function () {
    $parent = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $link = ParentGuardianLink::create([
        'parent_user_id' => $parent->id,
        'student_user_id' => $student->id,
        'relationship' => 'father',
    ]);

    expect($link->fresh()->tenant_id)->toBe($this->tenant->id)
        ->and($link->parentUser->id)->toBe($parent->id)
        ->and($link->student->id)->toBe($student->id);
});

it('refuses a second link for the same parent/student pair', function () {
    $parent = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);

    ParentGuardianLink::create([
        'parent_user_id' => $parent->id,
        'student_user_id' => $student->id,
        'relationship' => 'father',
    ]);

    expect(fn () => ParentGuardianLink::create([
        'parent_user_id' => $parent->id,
        'student_user_id' => $student->id,
        'relationship' => 'guardian',
    ]))->toThrow(QueryException::class);
});
