<?php

use App\Domains\Comms\Filament\Resources\HelpDeskTickets\HelpDeskTicketResource;
use App\Domains\Comms\Filament\Resources\HelpDeskTickets\Pages\ListHelpDeskTickets;
use App\Domains\Comms\Models\HelpDeskTicket;
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

    $this->requester = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->requester->assignRole('student');

    $this->ticket = HelpDeskTicket::create([
        'requester_id' => $this->requester->id,
        'subject' => 'Cannot log in',
        'body' => 'Password reset link never arrives.',
        'sla_due_at' => now()->addDay(),
    ]);

    $this->actingAs($this->admin)
        ->get(HelpDeskTicketResource::getUrl('index', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();
});

it('assigns a ticket to a staff member', function () {
    $staff = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $staff->assignRole('instructor');

    Livewire::actingAs($this->admin)
        ->test(ListHelpDeskTickets::class)
        ->callTableAction('assign', $this->ticket, data: ['assigned_to_user_id' => $staff->id])
        ->assertHasNoTableActionErrors();

    expect($this->ticket->fresh()->assigned_to_user_id)->toBe($staff->id);
});

it('creates a public reply visible to the requester and an internal note that is not', function () {
    Livewire::actingAs($this->admin)
        ->test(ListHelpDeskTickets::class)
        ->callTableAction('reply', $this->ticket, data: ['body' => 'Please check your spam folder.', 'is_internal_note' => false])
        ->assertHasNoTableActionErrors();

    Livewire::actingAs($this->admin)
        ->test(ListHelpDeskTickets::class)
        ->callTableAction('reply', $this->ticket, data: ['body' => 'Their email provider is blocking us.', 'is_internal_note' => true])
        ->assertHasNoTableActionErrors();

    $replies = $this->ticket->fresh()->replies;
    expect($replies)->toHaveCount(2);
    expect($replies->firstWhere('is_internal_note', false)->body)->toBe('Please check your spam folder.');
    expect($replies->firstWhere('is_internal_note', true)->body)->toBe('Their email provider is blocking us.');
    expect($replies->first()->author_id)->toBe($this->admin->id);
});

it('walks a ticket through open to in_progress to resolved to closed, hiding each action once past it', function () {
    $component = Livewire::actingAs($this->admin)->test(ListHelpDeskTickets::class);

    $component->assertTableActionVisible('markInProgress', $this->ticket)
        ->callTableAction('markInProgress', $this->ticket)
        ->assertHasNoTableActionErrors();
    expect($this->ticket->fresh()->status)->toBe('in_progress');

    $component->assertTableActionHidden('markInProgress', $this->ticket)
        ->assertTableActionVisible('resolve', $this->ticket)
        ->callTableAction('resolve', $this->ticket)
        ->assertHasNoTableActionErrors();
    expect($this->ticket->fresh()->status)->toBe('resolved');
    expect($this->ticket->fresh()->resolved_at)->not->toBeNull();

    $component->assertTableActionHidden('resolve', $this->ticket)
        ->assertTableActionVisible('close', $this->ticket)
        ->callTableAction('close', $this->ticket)
        ->assertHasNoTableActionErrors();
    expect($this->ticket->fresh()->status)->toBe('closed');

    $component->assertTableActionHidden('close', $this->ticket);
});

it('is not reachable from the Teach panel', function () {
    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');

    $this->actingAs($instructor)
        ->get('http://physics.lms.test/teach/help-desk-tickets')
        ->assertNotFound();
});
