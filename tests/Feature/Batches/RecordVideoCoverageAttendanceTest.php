<?php

use App\Domains\Batches\Events\AttendanceRecorded;
use App\Domains\Batches\Jobs\RecordVideoCoverageAttendance;
use App\Domains\Content\Models\AttendanceRecord;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function coverageFixture(): array
{
    test()->seed(RoleSeeder::class);

    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid()]);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create([
        'course_section_id' => $section->id,
        'title' => 'Velocity',
        'type' => 'video',
        'position' => 0,
        'video_status' => 'ready',
        'video_duration_seconds' => 100,
    ]);

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    return [$tenant, $lesson, $student];
}

function watch(Lesson $lesson, User $user, int $start, int $end): VideoWatchEvent
{
    return VideoWatchEvent::create([
        'lesson_id' => $lesson->id,
        'user_id' => $user->id,
        'start_s' => $start,
        'end_s' => $end,
    ]);
}

it('marks present once merged coverage reaches the 80% threshold', function () {
    [, $lesson, $student] = coverageFixture();
    watch($lesson, $student, 0, 80);

    app(RecordVideoCoverageAttendance::class)->handle();

    $record = AttendanceRecord::where('attendable_type', Lesson::class)
        ->where('attendable_id', $lesson->id)
        ->where('user_id', $student->id)
        ->sole();

    expect($record->status)->toBe('present')
        ->and($record->source)->toBe('video_coverage')
        ->and($record->recorded_at)->not->toBeNull();
});

it('fires AttendanceRecorded with the student, tenant, and present status once the threshold is crossed', function () {
    [$tenant, $lesson, $student] = coverageFixture();
    watch($lesson, $student, 0, 90);

    Event::fake([AttendanceRecorded::class]);

    app(RecordVideoCoverageAttendance::class)->handle();

    Event::assertDispatched(AttendanceRecorded::class, fn (AttendanceRecorded $event) => $event->userId === $student->id
        && $event->tenantId === $tenant->id
        && $event->status === 'present');
});

it('does not mark present below the 80% threshold', function () {
    [, $lesson, $student] = coverageFixture();
    watch($lesson, $student, 0, 70);

    app(RecordVideoCoverageAttendance::class)->handle();

    expect(AttendanceRecord::where('attendable_type', Lesson::class)->where('attendable_id', $lesson->id)->count())
        ->toBe(0);
});

it('merges overlapping segments instead of double-counting them', function () {
    [, $lesson, $student] = coverageFixture();
    // Naive summation reads 45 + 35 = 80 (exactly the threshold), but these
    // segments overlap between 40-45 — the real merged coverage is only
    // 0-75, i.e. 75%, which must NOT cross the threshold.
    watch($lesson, $student, 0, 45);
    watch($lesson, $student, 40, 75);

    app(RecordVideoCoverageAttendance::class)->handle();

    expect(AttendanceRecord::where('attendable_type', Lesson::class)->where('attendable_id', $lesson->id)->count())
        ->toBe(0);
});

it('sums disjoint and adjacent segments toward coverage', function () {
    [, $lesson, $student] = coverageFixture();
    watch($lesson, $student, 0, 40);
    watch($lesson, $student, 40, 60); // adjacent, extends the same block
    watch($lesson, $student, 70, 90); // disjoint, separate block

    app(RecordVideoCoverageAttendance::class)->handle();

    // Merged coverage = (0-60) + (70-90) = 60 + 20 = 80 = 80%.
    expect(AttendanceRecord::where('attendable_type', Lesson::class)->where('attendable_id', $lesson->id)->count())
        ->toBe(1);
});

it('is idempotent — re-running the job does not duplicate the record', function () {
    [, $lesson, $student] = coverageFixture();
    watch($lesson, $student, 0, 90);

    app(RecordVideoCoverageAttendance::class)->handle();
    app(RecordVideoCoverageAttendance::class)->handle();

    expect(AttendanceRecord::where('attendable_type', Lesson::class)->where('attendable_id', $lesson->id)->count())
        ->toBe(1);
});

it('skips a lesson with no known video duration', function () {
    [, $lesson, $student] = coverageFixture();
    $lesson->update(['video_duration_seconds' => null]);
    watch($lesson, $student, 0, 1000);

    app(RecordVideoCoverageAttendance::class)->handle();

    expect(AttendanceRecord::count())->toBe(0);
});

it('skips a lesson with a non-positive video duration', function () {
    [, $lesson, $student] = coverageFixture();
    $lesson->update(['video_duration_seconds' => 0]);
    watch($lesson, $student, 0, 1000);

    app(RecordVideoCoverageAttendance::class)->handle();

    expect(AttendanceRecord::count())->toBe(0);
});

it('creates the record with the right tenant_id even though the job runs with no CurrentTenant bound', function () {
    [$tenant, $lesson, $student] = coverageFixture();
    watch($lesson, $student, 0, 90);

    // The real scheduler runs this with nothing bound (bootstrap/app.php) —
    // BelongsToTenant's global scope reading VideoWatchEvent/Lesson no-ops
    // either way, but AttendanceRecord::create()'s tenant_id must still
    // come out right despite CurrentTenant being genuinely unbound.
    app(CurrentTenant::class)->clear();

    app(RecordVideoCoverageAttendance::class)->handle();

    app(CurrentTenant::class)->set($tenant);
    $record = AttendanceRecord::where('attendable_type', Lesson::class)->where('attendable_id', $lesson->id)->sole();
    expect($record->tenant_id)->toBe($tenant->id);
});

it('sweeps every tenants coverage in a single pass', function () {
    [$tenantA, $lessonA, $studentA] = coverageFixture();
    watch($lessonA, $studentA, 0, 90);

    [$tenantB, $lessonB, $studentB] = coverageFixture();
    watch($lessonB, $studentB, 0, 90);

    app(CurrentTenant::class)->clear();
    app(RecordVideoCoverageAttendance::class)->handle();

    app(CurrentTenant::class)->set($tenantA);
    expect(AttendanceRecord::where('attendable_type', Lesson::class)->where('attendable_id', $lessonA->id)->count())->toBe(1);

    app(CurrentTenant::class)->set($tenantB);
    expect(AttendanceRecord::where('attendable_type', Lesson::class)->where('attendable_id', $lessonB->id)->count())->toBe(1);
});

it('is a real queued job with retry configured', function () {
    // S9 B10: this was a plain class before — Schedule::job() ran it inline
    // (dispatchNow()), so $tries/backoff() would have been dead
    // configuration. Confirms the conversion actually took, not just that
    // the properties exist.
    expect(app(RecordVideoCoverageAttendance::class))
        ->toBeInstanceOf(ShouldQueue::class);

    $job = new RecordVideoCoverageAttendance;

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([60, 300, 900]);
});

it('sweeps every tenant even when CurrentTenant is left stale-bound to the other one, not explicitly cleared', function () {
    // Unlike "sweeps every tenants coverage in a single pass" above (which
    // explicitly clears CurrentTenant before calling handle()), this
    // exercises the exact new risk S9 B10 introduced by making this a real
    // queued job: a persistent Horizon worker can run any other
    // tenant-bound job right before this one in the same PHP process, and
    // nothing here would explicitly clear it first if handle() didn't do
    // so itself. coverageFixture() leaves CurrentTenant bound to whichever
    // tenant it created last (tenantB) by the time handle() runs below —
    // deliberately not cleared here, unlike the other cross-tenant test.
    [$tenantA, $lessonA, $studentA] = coverageFixture();
    watch($lessonA, $studentA, 0, 90);

    [$tenantB, $lessonB, $studentB] = coverageFixture();
    watch($lessonB, $studentB, 0, 90);

    expect(app(CurrentTenant::class)->id())->toBe($tenantB->id);

    app(RecordVideoCoverageAttendance::class)->handle();

    app(CurrentTenant::class)->set($tenantA);
    expect(AttendanceRecord::where('attendable_type', Lesson::class)->where('attendable_id', $lessonA->id)->count())->toBe(1);

    app(CurrentTenant::class)->set($tenantB);
    expect(AttendanceRecord::where('attendable_type', Lesson::class)->where('attendable_id', $lessonB->id)->count())->toBe(1);
});

it('marks present against a live_session lessons manually-attached recording too, not just video lessons', function () {
    test()->seed(RoleSeeder::class);

    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid()]);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create([
        'course_section_id' => $section->id,
        'title' => 'Live Q&A',
        'type' => 'live_session',
        'position' => 0,
        'video_status' => 'ready',
        'video_duration_seconds' => 100,
    ]);

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');
    watch($lesson, $student, 0, 90);

    app(RecordVideoCoverageAttendance::class)->handle();

    expect(AttendanceRecord::where('attendable_type', Lesson::class)->where('attendable_id', $lesson->id)->where('status', 'present')->exists())
        ->toBeTrue();
});

it('merges out-of-order segments the same as sorted ones', function () {
    $job = app(RecordVideoCoverageAttendance::class);

    $unsorted = collect([
        new VideoWatchEvent(['start_s' => 70, 'end_s' => 90]),
        new VideoWatchEvent(['start_s' => 0, 'end_s' => 40]),
        new VideoWatchEvent(['start_s' => 30, 'end_s' => 60]),
    ]);

    // (0-40)+(30-60) merge to (0-60)=60, plus (70-90)=20 -> 80 total.
    expect($job->mergedCoveredSeconds($unsorted))->toBe(80);
});
