<?php

use App\Domains\Comms\Jobs\HelpDeskSlaSweep;
use App\Domains\Comms\Models\HelpDeskTicket;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Notifications\HelpDeskSlaBreached;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->requester = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

it('notifies the assigned staff member for a breached, still-open ticket and marks it notified', function () {
    Notification::fake();

    $staff = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $ticket = HelpDeskTicket::create([
        'requester_id' => $this->requester->id,
        'assigned_to_user_id' => $staff->id,
        'subject' => 'Overdue',
        'body' => 'x',
        'sla_due_at' => now()->subHour(),
    ]);

    app(CurrentTenant::class)->clear();
    app(HelpDeskSlaSweep::class)->handle();
    app(CurrentTenant::class)->set($this->tenant);

    Notification::assertSentTo($staff, HelpDeskSlaBreached::class);
    expect($ticket->fresh()->sla_breach_notified_at)->not->toBeNull();
});

it('notifies every institute_admin when a breached ticket has no assignee', function () {
    Notification::fake();

    $admin1 = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $admin1->assignRole('institute_admin');
    $admin2 = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $admin2->assignRole('institute_admin');

    HelpDeskTicket::create([
        'requester_id' => $this->requester->id,
        'subject' => 'Unassigned overdue',
        'body' => 'x',
        'sla_due_at' => now()->subHour(),
    ]);

    app(CurrentTenant::class)->clear();
    app(HelpDeskSlaSweep::class)->handle();
    app(CurrentTenant::class)->set($this->tenant);

    Notification::assertSentTo($admin1, HelpDeskSlaBreached::class);
    Notification::assertSentTo($admin2, HelpDeskSlaBreached::class);
});

it('never flags a ticket that is not yet past its own sla_due_at', function () {
    Notification::fake();

    HelpDeskTicket::create(['requester_id' => $this->requester->id, 'subject' => 'On time', 'body' => 'x', 'sla_due_at' => now()->addHour()]);

    app(CurrentTenant::class)->clear();
    app(HelpDeskSlaSweep::class)->handle();

    Notification::assertNothingSent();
});

it('never flags an already-resolved or already-notified ticket', function () {
    Notification::fake();

    $resolved = HelpDeskTicket::create(['requester_id' => $this->requester->id, 'subject' => 'Resolved', 'body' => 'x', 'status' => 'resolved', 'sla_due_at' => now()->subHour()]);
    $alreadyNotified = HelpDeskTicket::create(['requester_id' => $this->requester->id, 'subject' => 'Already notified', 'body' => 'x', 'sla_due_at' => now()->subHour(), 'sla_breach_notified_at' => now()->subMinutes(30)]);

    app(CurrentTenant::class)->clear();
    $breached = app(HelpDeskSlaSweep::class)->breachedTickets();

    expect($breached->pluck('id'))->not->toContain($resolved->id);
    expect($breached->pluck('id'))->not->toContain($alreadyNotified->id);

    app(HelpDeskSlaSweep::class)->handle();
    Notification::assertNothingSent();
});
