<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Content\Models\Course;
use App\Domains\Operations\Jobs\InviteInstructorsFromEmailList;
use App\Domains\Tenancy\Models\InstructorInvitation;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Notifications\InstructorInvitationMail;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin->assignRole('institute_admin');

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $this->batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch A',
        'slug' => 'batch-a',
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
});

it('sends an invitation for each email, all assigned to the same batches', function () {
    Notification::fake();

    InviteInstructorsFromEmailList::dispatchSync(
        $this->tenant->id,
        ['teacher-a@example.com', 'teacher-b@example.com'],
        [$this->batch->id],
        $this->admin->id,
    );

    expect(InstructorInvitation::where('email', 'teacher-a@example.com')->exists())->toBeTrue();
    expect(InstructorInvitation::where('email', 'teacher-b@example.com')->exists())->toBeTrue();

    $invitationA = InstructorInvitation::where('email', 'teacher-a@example.com')->firstOrFail();
    expect($invitationA->batch_ids)->toBe([$this->batch->id]);
    expect($invitationA->invited_by_user_id)->toBe($this->admin->id);

    Notification::assertSentOnDemandTimes(InstructorInvitationMail::class, 2);
});
