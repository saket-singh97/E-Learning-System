<?php

use App\Domains\Planner\Livewire\Calendar;
use App\Domains\Planner\Models\PlannerEvent;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->viewer = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

it('renders the merged event set for the current month', function () {
    PlannerEvent::create(['user_id' => $this->viewer->id, 'title' => 'Study group', 'starts_at' => now()->addDay()]);

    Livewire::actingAs($this->viewer)
        ->test(Calendar::class)
        ->assertSee('Study group');
});

it('creates a custom event through the form', function () {
    Livewire::actingAs($this->viewer)
        ->test(Calendar::class)
        ->set('title', 'Doctor appointment')
        ->set('startsAt', now()->addDays(2)->format('Y-m-d\TH:i'))
        ->call('createEvent')
        ->assertHasNoErrors()
        ->assertSee('Doctor appointment');

    expect(PlannerEvent::where('user_id', $this->viewer->id)->where('title', 'Doctor appointment')->exists())->toBeTrue();
});

it('edits and deletes its own custom event', function () {
    $event = PlannerEvent::create(['user_id' => $this->viewer->id, 'title' => 'Old title', 'starts_at' => now()->addDay()]);

    Livewire::actingAs($this->viewer)
        ->test(Calendar::class)
        ->call('editEvent', $event->id)
        ->assertSet('title', 'Old title')
        ->set('title', 'New title')
        ->call('updateEvent')
        ->assertHasNoErrors();

    expect($event->fresh()->title)->toBe('New title');

    Livewire::actingAs($this->viewer)
        ->test(Calendar::class)
        ->call('deleteEvent', $event->id);

    expect(PlannerEvent::find($event->id))->toBeNull();
});

it('never lets a user edit or delete another user\'s custom event', function () {
    $otherUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $event = PlannerEvent::create(['user_id' => $otherUser->id, 'title' => 'Not mine', 'starts_at' => now()->addDay()]);

    Livewire::actingAs($this->viewer)
        ->test(Calendar::class)
        ->call('editEvent', $event->id)
        ->assertSet('editingEventId', null)
        ->assertSet('title', '');

    Livewire::actingAs($this->viewer)
        ->test(Calendar::class)
        ->call('deleteEvent', $event->id);

    expect(PlannerEvent::find($event->id))->not->toBeNull();
});

it('shows a different set of events after navigating to the next month', function () {
    PlannerEvent::create(['user_id' => $this->viewer->id, 'title' => 'This month', 'starts_at' => now()->addDay()]);
    PlannerEvent::create(['user_id' => $this->viewer->id, 'title' => 'Next month', 'starts_at' => now()->addMonthNoOverflow()->startOfMonth()->addDays(2)]);

    Livewire::actingAs($this->viewer)
        ->test(Calendar::class)
        ->assertSee('This month')
        ->assertDontSee('Next month')
        ->call('nextMonth')
        ->assertDontSee('This month')
        ->assertSee('Next month');
});
