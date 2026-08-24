<?php

use App\Domains\Batches\Filament\Resources\Batches\BatchResource;
use App\Domains\Batches\Filament\Resources\Batches\Pages\EditBatch;
use App\Domains\Batches\Filament\Resources\Batches\RelationManagers\BatchSessionsRelationManager;
use App\Domains\Batches\Filament\Resources\Batches\RelationManagers\EnrolmentsRelationManager;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Batches\Models\IdCard;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Operations\Filament\Resources\Enquiries\EnquiryResource;
use App\Domains\Operations\Filament\Resources\Enquiries\Pages\EditEnquiry;
use App\Domains\Operations\Filament\Resources\Enquiries\Pages\ListEnquiries;
use App\Domains\Operations\Filament\Resources\Enquiries\RelationManagers\FollowUpsRelationManager;
use App\Domains\Operations\Models\Enquiry;
use App\Domains\Operations\Models\EnquiryFollowUp;
use App\Domains\Tenancy\Filament\Resources\InstructorInvitations\InstructorInvitationResource;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\InstructorInvitationService;
use App\Livewire\InstructorInvitationAccept;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * S15 B21 — lifecycle E2E, same posture S11 B16/S12 B14/S13 B17/S14 B18
 * each already established: proves the real chains fire end to end through
 * the actual Filament resources/relation managers, not just that each
 * block's own model relationships line up in isolation.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

it('runs enquiry through follow-up, convert-to-enrolment, to ID card issued', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);

    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101-'.uniqid(), 'status' => 'published']);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);

    // 1. A prospect enquires (S4 pt.1, B13).
    $enquiry = Enquiry::create(['name' => 'Priya Sharma', 'email' => 'priya@example.com', 'status' => 'new']);

    // 2. Admissions counselor logs a follow-up through the real relation
    // manager (S4 pt.2, B14).
    $this->actingAs($admin)
        ->get(EnquiryResource::getUrl('edit', ['record' => $enquiry, 'subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    Livewire::test(FollowUpsRelationManager::class, [
        'ownerRecord' => $enquiry,
        'pageClass' => EditEnquiry::class,
    ])->callTableAction('create', data: [
        'note' => 'Called, interested, will decide next week.',
        'next_follow_up_at' => now()->addWeek()->toDateTimeString(),
    ])->assertHasNoTableActionErrors();

    $followUp = EnquiryFollowUp::where('enquiry_id', $enquiry->id)->firstOrFail();
    expect($followUp->user_id)->toBe($admin->id);

    // 3. Convert to a real Enrolment through the Filament table action —
    // writes through BatchEnrolmentService::addManually(), not a parallel
    // path (design decision 9).
    $this->actingAs($admin)
        ->get(EnquiryResource::getUrl('index', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    Livewire::test(ListEnquiries::class)
        ->callTableAction('convertToEnrolment', $enquiry, data: ['batch_id' => $batch->id])
        ->assertHasNoTableActionErrors();

    expect($enquiry->fresh()->status)->toBe('converted');
    $student = User::where('email', 'priya@example.com')->firstOrFail();
    expect($student->hasRole('student'))->toBeTrue();
    $enrolment = Enrolment::where('batch_id', $batch->id)->where('user_id', $student->id)->firstOrFail();
    expect($enquiry->fresh()->converted_enrolment_id)->toBe($enrolment->id);

    // 4. ID card issued on demand through the real "Issue ID card" row
    // action on the batch's own EnrolmentsRelationManager (S5, B15 —
    // design decision 10, never auto-issued).
    $this->actingAs($admin)
        ->get(BatchResource::getUrl('edit', ['record' => $batch, 'subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    Livewire::test(EnrolmentsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => \App\Domains\Batches\Filament\Resources\Batches\Pages\EditBatch::class,
    ])->callTableAction('issueIdCard', $enrolment)
        ->assertHasNoTableActionErrors();

    $idCard = IdCard::where('enrolment_id', $enrolment->id)->sole();
    expect($idCard->card_number)->toStartWith('ID-');
});

it('runs instructor invite through accept, assigned batch, to a conflict refused on a second overlapping session', function () {
    Notification::fake();

    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);

    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Mechanics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Live class', 'type' => 'live_session', 'position' => 0]);

    $batchA = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch A',
        'slug' => 'batch-a-'.uniqid(),
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    $batchB = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch B',
        'slug' => 'batch-b-'.uniqid(),
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);

    // 1. Admin invites an instructor to both batches (S.S1 real gap
    // closure, B0/B1).
    $invitation = app(InstructorInvitationService::class)->invite(
        'teacher@example.com',
        [$batchA->id, $batchB->id],
        $admin,
    );

    $this->actingAs($admin)
        ->get(InstructorInvitationResource::getUrl('index', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    // 2. Instructor accepts through the real host-agnostic Livewire page —
    // creates the User, grants the instructor role, assigns both batches.
    app(CurrentTenant::class)->clear();

    Livewire::test(InstructorInvitationAccept::class, ['token' => $invitation->token])
        ->set('name', 'Teacher One')
        ->set('password', 'secret-password')
        ->call('accept')
        ->assertHasNoErrors();

    $instructor = User::where('email', 'teacher@example.com')->firstOrFail();
    expect($instructor->hasRole('instructor'))->toBeTrue();
    expect($batchA->fresh()->instructor_id)->toBe($instructor->id);
    expect($batchB->fresh()->instructor_id)->toBe($instructor->id);

    app(CurrentTenant::class)->set($tenant);

    // 3. First session on Batch A schedules cleanly.
    BatchSession::create([
        'batch_id' => $batchA->id,
        'lesson_id' => $lesson->id,
        'starts_at' => '2026-09-01 09:00:00',
        'duration_minutes' => 60,
        'position' => 0,
        'live_provider' => 'external_link',
        'provider_meeting_ref' => 'https://example.test/meet',
    ]);

    // 4. A second, overlapping session on Batch B for the *same* now-
    // assigned instructor is refused (F7 pt.2, B4) — proves the whole
    // chain from invite through to conflict detection is really wired
    // together, not just that each half works in isolation.
    $this->actingAs($admin)
        ->get(BatchResource::getUrl('edit', ['record' => $batchB, 'subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    Livewire::test(BatchSessionsRelationManager::class, [
        'ownerRecord' => $batchB,
        'pageClass' => EditBatch::class,
    ])->callTableAction('create', data: [
        'starts_at' => '2026-09-01 09:30:00',
        'duration_minutes' => 30,
    ])->assertHasTableActionErrors(['starts_at']);

    expect(BatchSession::where('batch_id', $batchB->id)->count())->toBe(0);
});
