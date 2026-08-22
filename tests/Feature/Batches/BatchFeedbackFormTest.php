<?php

use App\Domains\Batches\Livewire\BatchFeedbackForm;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchFeedback;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $this->batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');
    Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $this->student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);
});

it('lets an enrolled student submit feedback', function () {
    Livewire::actingAs($this->student)
        ->test(BatchFeedbackForm::class, ['batch' => $this->batch])
        ->set('rating', 4)
        ->set('comment', 'Great batch')
        ->call('submit');

    $feedback = BatchFeedback::where('batch_id', $this->batch->id)->where('user_id', $this->student->id)->firstOrFail();
    expect($feedback->rating)->toBe(4);
    expect($feedback->comment)->toBe('Great batch');
});

it('updates the existing row on resubmission instead of duplicating', function () {
    Livewire::actingAs($this->student)
        ->test(BatchFeedbackForm::class, ['batch' => $this->batch])
        ->set('rating', 3)
        ->call('submit');

    Livewire::actingAs($this->student)
        ->test(BatchFeedbackForm::class, ['batch' => $this->batch])
        ->set('rating', 5)
        ->set('comment', 'Changed my mind')
        ->call('submit');

    expect(BatchFeedback::where('batch_id', $this->batch->id)->where('user_id', $this->student->id)->count())->toBe(1);
    $feedback = BatchFeedback::where('batch_id', $this->batch->id)->where('user_id', $this->student->id)->firstOrFail();
    expect($feedback->rating)->toBe(5);
});

it('no-ops for a student with no genuine enrolment', function () {
    $outsider = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $outsider->assignRole('student');

    Livewire::actingAs($outsider)
        ->test(BatchFeedbackForm::class, ['batch' => $this->batch])
        ->set('rating', 4)
        ->call('submit');

    expect(BatchFeedback::where('batch_id', $this->batch->id)->count())->toBe(0);
});

it('no-ops for a guest', function () {
    Livewire::test(BatchFeedbackForm::class, ['batch' => $this->batch])
        ->set('rating', 4)
        ->call('submit');

    expect(BatchFeedback::where('batch_id', $this->batch->id)->count())->toBe(0);
});
