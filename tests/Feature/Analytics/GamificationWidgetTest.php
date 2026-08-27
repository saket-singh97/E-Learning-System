<?php

use App\Domains\Analytics\Livewire\GamificationWidget;
use App\Domains\Analytics\Models\Badge;
use App\Domains\Analytics\Models\GamificationProfile;
use App\Domains\Analytics\Models\StudentBadge;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->viewer = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $this->batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch A',
        'slug' => 'batch-a-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    Enrolment::create(['batch_id' => $this->batch->id, 'user_id' => $this->viewer->id, 'status' => 'active', 'source' => 'manual_add', 'requested_at' => now()]);
});

it('shows the viewer\'s own xp, level, and streak', function () {
    GamificationProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->viewer->id, 'xp_total' => 150, 'level' => 2, 'current_streak_days' => 3, 'longest_streak_days' => 5]);

    Livewire::actingAs($this->viewer)
        ->test(GamificationWidget::class)
        ->assertSee('Level 2')
        ->assertSee('150 XP')
        ->assertSee('streak: 3');
});

it('defaults to level 1, 0 xp when no profile exists yet', function () {
    Livewire::actingAs($this->viewer)
        ->test(GamificationWidget::class)
        ->assertSee('Level 1')
        ->assertSee('0 XP');
});

it('shows the viewer\'s earned badges', function () {
    $badge = Badge::create(['tenant_id' => $this->tenant->id, 'key' => 'first-step', 'name' => 'First Step', 'description' => 'Earned first XP', 'icon' => 'star', 'xp_threshold' => 1]);
    StudentBadge::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->viewer->id, 'badge_id' => $badge->id, 'awarded_at' => now()]);

    Livewire::actingAs($this->viewer)
        ->test(GamificationWidget::class)
        ->assertSee('First Step');
});

it('ranks batchmates correctly on the leaderboard', function () {
    $other = User::factory()->create(['tenant_id' => $this->tenant->id]);
    Enrolment::create(['batch_id' => $this->batch->id, 'user_id' => $other->id, 'status' => 'active', 'source' => 'manual_add', 'requested_at' => now()]);

    GamificationProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->viewer->id, 'xp_total' => 50, 'level' => 1]);
    GamificationProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $other->id, 'xp_total' => 500, 'level' => 6]);

    Livewire::actingAs($this->viewer)
        ->test(GamificationWidget::class)
        ->set('batchId', $this->batch->id)
        ->assertSeeInOrder(['#1 '.$other->name, '#2 '.$this->viewer->name]);
});

it('hides the leaderboard but not the profile when the tenant opts out', function () {
    $this->tenant->update(['gamification_leaderboard_enabled' => false]);
    GamificationProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->viewer->id, 'xp_total' => 50, 'level' => 1]);

    Livewire::actingAs($this->viewer)
        ->test(GamificationWidget::class)
        ->assertSee('Level 1')
        ->assertSee('Leaderboard is disabled for this institute.')
        ->assertDontSee('#1');
});
