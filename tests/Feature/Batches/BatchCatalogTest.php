<?php

use App\Domains\Batches\Livewire\BatchCatalog;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function batchCatalogUrl(string $subdomain): string
{
    return "http://{$subdomain}.lms.test/batches";
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);

    $this->batch = fn (array $overrides = []) => Batch::create(array_merge([
        'course_id' => $this->course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-batch-'.uniqid(),
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'self_enrol_direct',
        'status' => 'published',
    ], $overrides));

    $this->student = function (): User {
        $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $student->assignRole('student');

        return $student;
    };
});

it('lists a published batch on its own tenant but not on another tenant', function () {
    ($this->batch)(['title' => 'Physics Morning Batch']);

    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);

    $this->get(batchCatalogUrl('physics'))
        ->assertOk()
        ->assertSeeLivewire(BatchCatalog::class)
        ->assertSee('Physics Morning Batch');

    $this->get(batchCatalogUrl('chemistry'))
        ->assertOk()
        ->assertDontSee('Physics Morning Batch');
});

it('excludes a draft batch and a batch whose course is not published', function () {
    ($this->batch)(['title' => 'Draft Batch', 'status' => 'draft']);

    $draftCourse = Course::create(['title' => 'Draft Course', 'slug' => 'draft-course', 'status' => 'draft']);
    ($this->batch)(['title' => 'Unpublished Course Batch', 'course_id' => $draftCourse->id]);

    Livewire::test(BatchCatalog::class)
        ->assertDontSee('Draft Batch')
        ->assertDontSee('Unpublished Course Batch');
});

it('shows a Join button and lands the student active on a self_enrol_direct batch with a seat open', function () {
    $batch = ($this->batch)(['seat_limit' => 10]);
    $student = ($this->student)();

    Livewire::actingAs($student)
        ->test(BatchCatalog::class)
        ->assertSee('Join')
        ->call('join', $batch->id)
        ->assertSee('Active');

    expect(Enrolment::where('batch_id', $batch->id)->where('user_id', $student->id)->first()->status)
        ->toBe('active');
});

it('waitlists and shows position when the batch is full', function () {
    $batch = ($this->batch)(['seat_limit' => 1]);
    $first = ($this->student)();
    $second = ($this->student)();

    app(\App\Domains\Batches\Services\BatchEnrolmentService::class)->enrol($first, $batch);

    Livewire::actingAs($second)
        ->test(BatchCatalog::class)
        ->call('join', $batch->id)
        ->assertSee('Waitlisted')
        ->assertSee('position 1');
});

it('shows a Request to join button and lands pending_approval on a self_enrol_approval batch', function () {
    $batch = ($this->batch)(['self_enrol_mode' => 'self_enrol_approval']);
    $student = ($this->student)();

    Livewire::actingAs($student)
        ->test(BatchCatalog::class)
        ->assertSee('Request to join')
        ->call('join', $batch->id)
        ->assertSee('Request pending');

    expect(Enrolment::where('batch_id', $batch->id)->where('user_id', $student->id)->first()->status)
        ->toBe('pending_approval');
});

it('refuses a forced join call on a draft batch, bypassing the catalog\'s published-only display query', function () {
    $batch = ($this->batch)(['status' => 'draft']);
    $student = ($this->student)();

    expect(fn () => Livewire::actingAs($student)
        ->test(BatchCatalog::class)
        ->call('join', $batch->id))
        ->toThrow(RuntimeException::class);

    expect(Enrolment::where('batch_id', $batch->id)->count())->toBe(0);
});

it('breaks a created_at tie by insertion order when showing waitlist position', function () {
    // enrolments.created_at is whole-second precision in Postgres — two
    // joins in the same second tie, and without an id tiebreak both would
    // show the same "position", misleading whichever one actually joined
    // second into thinking they're ahead of the other.
    $batch = ($this->batch)(['seat_limit' => 1]);
    $service = app(\App\Domains\Batches\Services\BatchEnrolmentService::class);

    $service->enrol(($this->student)(), $batch);
    $first = ($this->student)();
    $second = ($this->student)();
    $firstEnrolment = $service->enrol($first, $batch);
    $secondEnrolment = $service->enrol($second, $batch);

    $tiedAt = $firstEnrolment->created_at;
    $firstEnrolment->forceFill(['created_at' => $tiedAt])->save();
    $secondEnrolment->forceFill(['created_at' => $tiedAt])->save();

    Livewire::actingAs($first)->test(BatchCatalog::class)->assertSee('position 1');
    Livewire::actingAs($second)->test(BatchCatalog::class)->assertSee('position 2');
});

it('offers no join action on a manual_only batch and refuses a forced join call', function () {
    $batch = ($this->batch)(['self_enrol_mode' => 'manual_only']);
    $student = ($this->student)();

    Livewire::actingAs($student)
        ->test(BatchCatalog::class)
        ->assertDontSee('Join')
        ->assertSee('Contact your institute to enrol')
        ->call('join', $batch->id);

    expect(Enrolment::where('batch_id', $batch->id)->count())->toBe(0);
});

it('does not enrol a guest who calls join directly', function () {
    $batch = ($this->batch)(['seat_limit' => 10]);

    Livewire::test(BatchCatalog::class)
        ->assertSee('Log in to join')
        ->call('join', $batch->id);

    expect(Enrolment::where('batch_id', $batch->id)->count())->toBe(0);
});

it('seats-remaining count drops after a join', function () {
    $batch = ($this->batch)(['seat_limit' => 2]);
    $student = ($this->student)();

    Livewire::actingAs($student)
        ->test(BatchCatalog::class)
        ->assertSee('2 open')
        ->call('join', $batch->id)
        ->assertSee('1 open');
});
