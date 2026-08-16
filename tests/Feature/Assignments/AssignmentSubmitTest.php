<?php

use App\Domains\Assignments\Models\Assignment;
use App\Domains\Assignments\Models\AssignmentSubmission;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Livewire\AssignmentSubmit;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $this->section = CourseSection::create(['course_id' => $this->course->id, 'title' => 'Kinematics', 'position' => 0]);
    $this->lesson = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'Homework 1', 'type' => 'assignment', 'position' => 0]);

    $this->assignment = Assignment::create([
        'lesson_id' => $this->lesson->id,
        'title' => 'Kinematics Problem Set',
        'due_at' => now()->addWeek(),
        'max_marks' => 50,
        'allowed_file_types' => ['pdf', 'jpg'],
        'max_file_size_kb' => 5000,
    ]);

    $this->batch = Batch::create([
        'course_id' => $this->course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-batch',
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonths(3)->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');
});

// Prefixed (not a bare "enrolStudent") — Pest loads every test file's global
// functions into one shared namespace, and a generic name like that already
// collides with an identically-named helper in another Feature test file.
function enrolStudentInAssignmentBatch(): void
{
    Enrolment::create([
        'batch_id' => test()->batch->id,
        'user_id' => test()->student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);
}

it('lets an enrolled student submit before the due date', function () {
    enrolStudentInAssignmentBatch();

    Livewire::actingAs($this->student)
        ->test(AssignmentSubmit::class, ['assignment' => $this->assignment])
        ->set('files', [UploadedFile::fake()->create('answer.pdf', 500, 'application/pdf')])
        ->call('submit')
        ->assertHasNoErrors();

    $submission = AssignmentSubmission::sole();

    expect($submission->assignment_id)->toBe($this->assignment->id)
        ->and($submission->user_id)->toBe($this->student->id)
        ->and($submission->version_number)->toBe(1)
        ->and($submission->is_late)->toBeFalse()
        ->and($submission->files)->toHaveCount(1)
        ->and($submission->files[0]['original_name'])->toBe('answer.pdf');
});

it('flags a submission made after the due date as late', function () {
    enrolStudentInAssignmentBatch();
    $this->assignment->update(['due_at' => now()->subDay()]);

    Livewire::actingAs($this->student)
        ->test(AssignmentSubmit::class, ['assignment' => $this->assignment])
        ->set('files', [UploadedFile::fake()->create('answer.pdf', 500, 'application/pdf')])
        ->call('submit')
        ->assertHasNoErrors();

    expect(AssignmentSubmission::sole()->is_late)->toBeTrue();
});

it('rejects a file type outside the assignment allow-list', function () {
    enrolStudentInAssignmentBatch();

    Livewire::actingAs($this->student)
        ->test(AssignmentSubmit::class, ['assignment' => $this->assignment])
        ->set('files', [UploadedFile::fake()->create('answer.exe', 500, 'application/octet-stream')])
        ->call('submit')
        ->assertHasErrors(['files.*']);

    expect(AssignmentSubmission::count())->toBe(0);
});

it('rejects more than 5 files', function () {
    enrolStudentInAssignmentBatch();

    $files = collect(range(1, 6))
        ->map(fn (int $i) => UploadedFile::fake()->create("answer{$i}.pdf", 100, 'application/pdf'))
        ->all();

    Livewire::actingAs($this->student)
        ->test(AssignmentSubmit::class, ['assignment' => $this->assignment])
        ->set('files', $files)
        ->call('submit')
        ->assertHasErrors(['files']);

    expect(AssignmentSubmission::count())->toBe(0);
});

it('refuses submission from a student with no active enrolment in a batch offering the course', function () {
    // Deliberately no enrolStudentInAssignmentBatch() call.
    Livewire::actingAs($this->student)
        ->test(AssignmentSubmit::class, ['assignment' => $this->assignment])
        ->set('files', [UploadedFile::fake()->create('answer.pdf', 500, 'application/pdf')])
        ->call('submit')
        ->assertHasErrors(['submission']);

    expect(AssignmentSubmission::count())->toBe(0);
});

it('lets a student resubmit, writing a new version rather than overwriting the first', function () {
    enrolStudentInAssignmentBatch();

    Livewire::actingAs($this->student)
        ->test(AssignmentSubmit::class, ['assignment' => $this->assignment])
        ->set('files', [UploadedFile::fake()->create('first.pdf', 500, 'application/pdf')])
        ->call('submit')
        ->assertHasNoErrors();

    Livewire::actingAs($this->student)
        ->test(AssignmentSubmit::class, ['assignment' => $this->assignment])
        ->set('files', [UploadedFile::fake()->create('second.pdf', 500, 'application/pdf')])
        ->call('submit')
        ->assertHasNoErrors();

    $versions = AssignmentSubmission::where('assignment_id', $this->assignment->id)
        ->orderBy('version_number')
        ->get();

    expect($versions)->toHaveCount(2)
        ->and($versions[0]->version_number)->toBe(1)
        ->and($versions[0]->files[0]['original_name'])->toBe('first.pdf')
        ->and($versions[1]->version_number)->toBe(2)
        ->and($versions[1]->files[0]['original_name'])->toBe('second.pdf');
});

it('preserves a graded version\'s marks and feedback when a later version is submitted', function () {
    enrolStudentInAssignmentBatch();

    Livewire::actingAs($this->student)
        ->test(AssignmentSubmit::class, ['assignment' => $this->assignment])
        ->set('files', [UploadedFile::fake()->create('first.pdf', 500, 'application/pdf')])
        ->call('submit')
        ->assertHasNoErrors();

    $firstVersion = AssignmentSubmission::sole();
    $firstVersion->update(['marks_awarded' => 40, 'feedback' => 'Good start', 'graded_at' => now()]);

    Livewire::actingAs($this->student)
        ->test(AssignmentSubmit::class, ['assignment' => $this->assignment])
        ->set('files', [UploadedFile::fake()->create('second.pdf', 500, 'application/pdf')])
        ->call('submit')
        ->assertHasNoErrors();

    expect($firstVersion->fresh()->marks_awarded)->toEqual(40)
        ->and($firstVersion->fresh()->feedback)->toBe('Good start')
        ->and(AssignmentSubmission::where('version_number', 2)->sole()->graded_at)->toBeNull();
});

it('refuses a 4th submission once 3 versions already exist', function () {
    enrolStudentInAssignmentBatch();

    foreach (['v1.pdf', 'v2.pdf', 'v3.pdf'] as $name) {
        Livewire::actingAs($this->student)
            ->test(AssignmentSubmit::class, ['assignment' => $this->assignment])
            ->set('files', [UploadedFile::fake()->create($name, 500, 'application/pdf')])
            ->call('submit')
            ->assertHasNoErrors();
    }

    Livewire::actingAs($this->student)
        ->test(AssignmentSubmit::class, ['assignment' => $this->assignment])
        ->set('files', [UploadedFile::fake()->create('v4.pdf', 500, 'application/pdf')])
        ->call('submit')
        ->assertHasErrors(['submission']);

    expect(AssignmentSubmission::count())->toBe(3);
});

it('lets an instructor open the assignment without a batch enrolment', function () {
    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');

    expect($this->assignment->isVisibleTo($instructor))->toBeTrue();
});

it('recovers from a concurrent-submission race instead of surfacing a raw database error', function () {
    // S10 B15 review pass: AssignmentSubmissionService::submit() computed
    // version_number from a plain count() with no lock — two requests
    // racing the same count (two tabs, a double-click) could both aim for
    // the same version_number, and the loser hit the DB's own unique
    // constraint as an uncaught QueryException (a raw 500) instead of
    // either succeeding cleanly or failing with a real message.
    //
    // Simulated via DB::listen() on the service's own count() query, not
    // AssignmentSubmission::creating() — the fix wraps the actual insert in
    // its own DB::transaction() (a SAVEPOINT, needed separately to stop a
    // unique-constraint failure from poisoning RefreshDatabase's own outer
    // test transaction, SQLSTATE 25P02, the identical class S10 B11's
    // review already hit once). A 'creating' listener fires *inside* that
    // savepoint, so a competing insert made there gets rolled back together
    // with the failed attempt when the savepoint recovers — it never
    // actually simulates a surviving concurrent row. Hooking the count()
    // query instead — which runs *before* the savepoint opens — means the
    // simulated race lands in the outer transaction and survives the
    // savepoint's own rollback, the same way a genuinely separate
    // connection's already-committed row would.
    enrolStudentInAssignmentBatch();

    $racedOnce = false;

    DB::listen(function ($query) use (&$racedOnce): void {
        if ($racedOnce
            || ! str_contains($query->sql, 'assignment_submissions')
            || ! str_contains(strtolower($query->sql), 'count(')
        ) {
            return;
        }

        $racedOnce = true;

        // withoutEvents() also skips BelongsToTenant's own creating() hook
        // (the thing that would normally set tenant_id from CurrentTenant),
        // so it must be set explicitly here — the exact gotcha
        // RecordVideoCoverageAttendance/CertificateService already
        // document for every other withoutEvents() create() in this app.
        AssignmentSubmission::withoutEvents(fn () => AssignmentSubmission::create([
            'tenant_id' => $this->tenant->id,
            'assignment_id' => $this->assignment->id,
            'user_id' => $this->student->id,
            'version_number' => 1,
            'files' => [],
            'submitted_at' => now(),
            'is_late' => false,
        ]));
    });

    Livewire::actingAs($this->student)
        ->test(AssignmentSubmit::class, ['assignment' => $this->assignment])
        ->set('files', [UploadedFile::fake()->create('answer.pdf', 500, 'application/pdf')])
        ->call('submit')
        ->assertHasNoErrors();

    // The retry recomputed a fresh count (now 1, since the "concurrent"
    // request's row landed first) and correctly produced version 2 —
    // not a 500, not a silently-dropped submission.
    $versions = AssignmentSubmission::where('assignment_id', $this->assignment->id)
        ->orderBy('version_number')
        ->pluck('version_number');

    expect($versions->all())->toBe([1, 2]);
});
