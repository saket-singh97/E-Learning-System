<?php

use App\Domains\Analytics\Models\Badge;
use App\Domains\Analytics\Models\StudentBadge;
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

it('auto-awards an xp-threshold badge exactly once when the threshold is first met', function () {
    $badge = Badge::create(['key' => 'centurion', 'name' => 'Centurion', 'xp_threshold' => 100]);

    $service = app(GamificationService::class);
    $service->awardXp($this->student->id, $this->tenant->id, 60);
    expect(StudentBadge::where('user_id', $this->student->id)->count())->toBe(0);

    $service->awardXp($this->student->id, $this->tenant->id, 50);
    expect(StudentBadge::where('user_id', $this->student->id)->where('badge_id', $badge->id)->count())->toBe(1);

    // A later award, still above threshold, must not create a second row.
    $service->awardXp($this->student->id, $this->tenant->id, 10);
    expect(StudentBadge::where('user_id', $this->student->id)->where('badge_id', $badge->id)->count())->toBe(1);
});

it('auto-awards a streak-criteria badge when the streak length is first met', function () {
    $badge = Badge::create(['key' => 'week-streak', 'name' => 'Week Streak', 'criteria' => ['streak_days' => 3]]);

    $service = app(GamificationService::class);
    $profile = $service->awardXp($this->student->id, $this->tenant->id, 5);

    for ($i = 1; $i <= 2; $i++) {
        $profile->update(['last_activity_date' => now()->subDay()->toDateString()]);
        $profile = $service->awardXp($this->student->id, $this->tenant->id, 5);
    }

    expect($profile->current_streak_days)->toBe(3)
        ->and(StudentBadge::where('user_id', $this->student->id)->where('badge_id', $badge->id)->count())->toBe(1);
});

it('never awards a badge belonging to another tenant', function () {
    $otherTenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($otherTenant);
    Badge::create(['key' => 'other-tenant-badge', 'name' => 'Other', 'xp_threshold' => 10]);

    app(CurrentTenant::class)->set($this->tenant);
    app(GamificationService::class)->awardXp($this->student->id, $this->tenant->id, 100);

    expect(StudentBadge::where('user_id', $this->student->id)->count())->toBe(0);
});
