<?php

use App\Domains\Comms\Events\HelpDeskTicketReplyCreated;
use App\Domains\Comms\Filament\Resources\HelpDeskTickets\HelpDeskTicketResource;
use App\Domains\Comms\Filament\Resources\HelpDeskTickets\Pages\ListHelpDeskTickets;
use App\Domains\Comms\Models\HelpDeskTicket;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin->assignRole('institute_admin');

    $this->requester = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->staff = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->ticket = HelpDeskTicket::create([
        'requester_id' => $this->requester->id,
        'assigned_to_user_id' => $this->staff->id,
        'subject' => 'Cannot log in',
        'body' => 'x',
        'sla_due_at' => now()->addDay(),
    ]);

    config(['broadcasting.default' => 'reverb']);
    require base_path('routes/channels.php');
});

it('authorizes the requester and the assigned staff member for the ticket channel', function () {
    $this->actingAs($this->requester)
        ->postJson('/broadcasting/auth', ['channel_name' => 'private-help-desk-ticket.'.$this->ticket->id, 'socket_id' => '1234.1234'])
        ->assertOk();

    $this->actingAs($this->staff)
        ->postJson('/broadcasting/auth', ['channel_name' => 'private-help-desk-ticket.'.$this->ticket->id, 'socket_id' => '1234.1234'])
        ->assertOk();
});

it('refuses an unrelated tenant user and a different tenant entirely', function () {
    $unrelated = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->actingAs($unrelated)
        ->postJson('/broadcasting/auth', ['channel_name' => 'private-help-desk-ticket.'.$this->ticket->id, 'socket_id' => '1234.1234'])
        ->assertForbidden();

    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    $otherTenantUser = User::factory()->create(['tenant_id' => $otherTenant->id]);

    $this->actingAs($otherTenantUser)
        ->postJson('/broadcasting/auth', ['channel_name' => 'private-help-desk-ticket.'.$this->ticket->id, 'socket_id' => '1234.1234'])
        ->assertForbidden();
});

it('broadcasts a public reply on the ticket\'s own channel', function () {
    Event::fake([HelpDeskTicketReplyCreated::class]);

    $this->actingAs($this->admin)
        ->get(HelpDeskTicketResource::getUrl('index', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    Livewire::actingAs($this->admin)
        ->test(ListHelpDeskTickets::class)
        ->callTableAction('reply', $this->ticket, data: ['body' => 'Please try again', 'is_internal_note' => false]);

    Event::assertDispatched(HelpDeskTicketReplyCreated::class, function (HelpDeskTicketReplyCreated $event) {
        return (string) $event->broadcastOn()[0] === 'private-help-desk-ticket.'.$this->ticket->id
            && $event->broadcastAs() === 'reply.created'
            && $event->broadcastWith()['body'] === 'Please try again';
    });
});

it('never broadcasts an internal note', function () {
    Event::fake([HelpDeskTicketReplyCreated::class]);

    $this->actingAs($this->admin)
        ->get(HelpDeskTicketResource::getUrl('index', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    Livewire::actingAs($this->admin)
        ->test(ListHelpDeskTickets::class)
        ->callTableAction('reply', $this->ticket, data: ['body' => 'Internal only', 'is_internal_note' => true]);

    Event::assertNotDispatched(HelpDeskTicketReplyCreated::class);
});
