<?php

use App\Domains\AI\Livewire\WhatsAppLinkCode;
use App\Domains\AI\Models\WhatsAppLink;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

it('generates a linking code for the logged-in student', function () {
    Livewire::actingAs($this->student)
        ->test(WhatsAppLinkCode::class)
        ->call('generate')
        ->assertSee('Text this code to our WhatsApp number');
});

it('shows the linked phone once a WhatsAppLink row exists, not the generate button', function () {
    WhatsAppLink::create(['user_id' => $this->student->id, 'phone_e164' => '+919876543210', 'verified_at' => now()]);

    Livewire::actingAs($this->student)
        ->test(WhatsAppLinkCode::class)
        ->assertSee('WhatsApp linked: +919876543210')
        ->assertDontSee('Link WhatsApp');
});

it('does nothing for a guest instead of erroring', function () {
    Livewire::test(WhatsAppLinkCode::class)
        ->call('generate')
        ->assertSee('Link WhatsApp')
        ->assertDontSee('Text this code');
});
