<?php

use App\Domains\Analytics\Models\GamificationProfile;
use App\Domains\Analytics\Services\GamificationService;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

it('creates a profile on first award and accumulates xp on later ones', function () {
    $service = app(GamificationService::class);

    $profile = $service->awardXp($this->student->id, $this->tenant->id, 10);
    expect($profile->xp_total)->toBe(10)->and($profile->level)->toBe(1);

    $profile = $service->awardXp($this->student->id, $this->tenant->id, 95);
    expect($profile->xp_total)->toBe(105)->and($profile->level)->toBe(2);

    expect(GamificationProfile::where('user_id', $this->student->id)->count())->toBe(1);
});

it('starts a streak at 1 and increments it on the next consecutive day', function () {
    $service = app(GamificationService::class);

    $profile = $service->awardXp($this->student->id, $this->tenant->id, 10);
    expect($profile->current_streak_days)->toBe(1)->and($profile->longest_streak_days)->toBe(1);

    $profile->update(['last_activity_date' => now()->subDay()->toDateString()]);

    $profile = $service->awardXp($this->student->id, $this->tenant->id, 10);
    expect($profile->current_streak_days)->toBe(2)->and($profile->longest_streak_days)->toBe(2);
});

it('does not double-increment the streak for a second event on the same day', function () {
    $service = app(GamificationService::class);

    $service->awardXp($this->student->id, $this->tenant->id, 10);
    $profile = $service->awardXp($this->student->id, $this->tenant->id, 10);

    expect($profile->xp_total)->toBe(20)->and($profile->current_streak_days)->toBe(1);
});

it('resets the streak after a missed day but keeps the longest-streak high-water mark', function () {
    $service = app(GamificationService::class);

    $profile = $service->awardXp($this->student->id, $this->tenant->id, 10);
    $profile->update(['last_activity_date' => now()->subDay()->toDateString()]);
    $profile = $service->awardXp($this->student->id, $this->tenant->id, 10);
    expect($profile->current_streak_days)->toBe(2);

    $profile->update(['last_activity_date' => now()->subDays(3)->toDateString()]);
    $profile = $service->awardXp($this->student->id, $this->tenant->id, 10);

    expect($profile->current_streak_days)->toBe(1)->and($profile->longest_streak_days)->toBe(2);
});
