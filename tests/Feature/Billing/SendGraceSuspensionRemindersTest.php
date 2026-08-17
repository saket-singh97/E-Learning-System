<?php

use App\Domains\Billing\Jobs\SendGraceSuspensionReminders;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\TenantSubscription;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Notifications\GracePeriodReminder;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->plan = Plan::create([
        'code' => 'growth-suspension-reminder-test',
        'name' => 'Growth',
        'storage_limit_gb' => 100,
        'bandwidth_limit_gb_per_day' => 50,
        'ai_spend_cap_usd_per_day' => 10,
        'price_inr_per_month' => 7999,
    ]);
});

function graceSubscriptionFixture(string $graceEndsAt): array
{
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');

    $subscription = TenantSubscription::create([
        'plan_id' => test()->plan->id,
        'status' => TenantSubscription::STATUS_GRACE,
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now(),
        'grace_ends_at' => $graceEndsAt,
    ]);

    app(CurrentTenant::class)->clear();

    return [$tenant, $admin, $subscription];
}

it('notifies institute_admins exactly 3 days before the grace deadline', function () {
    Notification::fake();
    [, $admin, $subscription] = graceSubscriptionFixture(now()->addDays(3)->toDateTimeString());

    (new SendGraceSuspensionReminders)->handle();

    Notification::assertSentTo(
        $admin,
        fn (GracePeriodReminder $notification) => $notification->stage === 'suspension_imminent'
            && $notification->subscription->id === $subscription->id,
    );
});

it('does not notify when the grace deadline is not exactly 3 days away', function () {
    Notification::fake();
    [, $admin] = graceSubscriptionFixture(now()->addDays(5)->toDateTimeString());

    (new SendGraceSuspensionReminders)->handle();

    Notification::assertNotSentTo($admin, GracePeriodReminder::class);
});

it('does not double-send if the job runs twice on the same day', function () {
    // No Notification::fake() here on purpose — the dedupe guard
    // (alreadySentToday()) reads back the real 'database' channel row,
    // same reasoning SendFeeRemindersTest's own equivalent case documents
    // (a queued job's retry can legitimately run the same schedule tick
    // twice, and Notification::fake() would hide the exact bug this test
    // exists to catch, since fake() never writes the row the dedupe
    // check reads).
    [, $admin] = graceSubscriptionFixture(now()->addDays(3)->toDateTimeString());

    (new SendGraceSuspensionReminders)->handle();
    (new SendGraceSuspensionReminders)->handle();

    expect($admin->notifications()->where('type', GracePeriodReminder::class)->count())->toBe(1);
});

it('skips a subscription with no institute_admin at all, without throwing', function () {
    Notification::fake();
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    TenantSubscription::create([
        'plan_id' => $this->plan->id,
        'status' => TenantSubscription::STATUS_GRACE,
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now(),
        'grace_ends_at' => now()->addDays(3),
    ]);
    app(CurrentTenant::class)->clear();

    expect(fn () => (new SendGraceSuspensionReminders)->handle())->not->toThrow(Throwable::class);
});
