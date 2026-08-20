<?php

use App\Domains\Comms\Filament\Resources\MessageReports\MessageReportResource;
use App\Domains\Comms\Filament\Resources\MessageReports\Pages\ListMessageReports;
use App\Domains\Comms\Models\DirectMessage;
use App\Domains\Comms\Models\MessageReport;
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

    $sender = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $recipient = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $message = DirectMessage::create(['sender_id' => $sender->id, 'recipient_id' => $recipient->id, 'body' => 'Nasty stuff']);

    $this->report = MessageReport::create(['reporter_id' => $recipient->id, 'direct_message_id' => $message->id, 'reason' => 'Abusive language']);

    $this->actingAs($this->admin)
        ->get(MessageReportResource::getUrl('index', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();
});

it('lists an open report with its reason and status', function () {
    Livewire::test(ListMessageReports::class)
        ->assertSee('Abusive language')
        ->assertSee('open');
});

it('marks a report actioned, which hides the message from view in the queue status', function () {
    Livewire::test(ListMessageReports::class)
        ->callTableAction('markActioned', $this->report);

    expect($this->report->fresh()->status)->toBe('actioned');
});

it('dismisses a report, clearing the flag without touching the message', function () {
    Livewire::test(ListMessageReports::class)
        ->callTableAction('dismiss', $this->report);

    expect($this->report->fresh()->status)->toBe('dismissed');
});

it('refuses a non-admin', function () {
    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');

    $this->actingAs($instructor)
        ->get(MessageReportResource::getUrl('index', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertForbidden();
});
