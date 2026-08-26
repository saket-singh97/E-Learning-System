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

it('serves a well-formed ics feed containing the expected events', function () {
    PlannerEvent::create(['user_id' => $this->viewer->id, 'title' => 'Study group, weekly', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);

    $feedUrl = Livewire::actingAs($this->viewer)->test(Calendar::class)->instance()->feedUrl();

    $response = $this->get($feedUrl)->assertOk();
    $response->assertHeader('Content-Type', 'text/calendar; charset=UTF-8');

    $ics = $response->getContent();
    expect($ics)->toStartWith("BEGIN:VCALENDAR\r\n");
    expect($ics)->toEndWith("END:VCALENDAR\r\n");
    expect($ics)->toContain("BEGIN:VEVENT\r\n");
    expect($ics)->toContain('SUMMARY:Study group\, weekly');
    expect(substr_count($ics, 'BEGIN:VEVENT'))->toBe(1);
    expect(substr_count($ics, 'END:VEVENT'))->toBe(1);
});

it('refuses an unsigned request', function () {
    $this->get("http://physics.lms.test/calendar/{$this->viewer->id}/feed.ics")
        ->assertForbidden();
});

it('refuses a tampered signature naming a different user', function () {
    $feedUrl = Livewire::actingAs($this->viewer)->test(Calendar::class)->instance()->feedUrl();

    $otherUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $tamperedUrl = str_replace("/calendar/{$this->viewer->id}/feed.ics", "/calendar/{$otherUser->id}/feed.ics", $feedUrl);

    $this->get($tamperedUrl)->assertForbidden();
});

it('never leaks one user\'s events through another user\'s own valid feed url', function () {
    PlannerEvent::create(['user_id' => $this->viewer->id, 'title' => 'Mine only', 'starts_at' => now()->addDay()]);

    $otherUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
    PlannerEvent::create(['user_id' => $otherUser->id, 'title' => 'Not mine', 'starts_at' => now()->addDay()]);

    $otherFeedUrl = Livewire::actingAs($otherUser)->test(Calendar::class)->instance()->feedUrl();

    $ics = $this->get($otherFeedUrl)->assertOk()->getContent();
    expect($ics)->toContain('SUMMARY:Not mine');
    expect($ics)->not->toContain('SUMMARY:Mine only');
});
