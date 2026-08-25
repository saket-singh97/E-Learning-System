<?php

use App\Domains\AI\Livewire\PhotoDoubtUpload;
use App\Domains\AI\Models\PhotoDoubtQuery;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

it('shows only the viewers own queries, never another students', function () {
    PhotoDoubtQuery::create([
        'user_id' => $this->student->id,
        'image_disk_path' => 'photo-doubts/own.jpg',
        'status' => 'ready',
        'solution' => 'MY OWN SOLUTION',
    ]);

    $classmate = User::factory()->create(['tenant_id' => $this->tenant->id]);
    PhotoDoubtQuery::create([
        'user_id' => $classmate->id,
        'image_disk_path' => 'photo-doubts/classmate.jpg',
        'status' => 'ready',
        'solution' => 'CLASSMATES SOLUTION',
    ]);

    $this->actingAs($this->student);

    Livewire::test(PhotoDoubtUpload::class)
        ->assertSee('MY OWN SOLUTION')
        ->assertDontSee('CLASSMATES SOLUTION');
});

it('reflects pending, ready, and failed states correctly', function () {
    PhotoDoubtQuery::create([
        'user_id' => $this->student->id,
        'image_disk_path' => 'photo-doubts/pending.jpg',
        'status' => 'pending',
    ]);
    PhotoDoubtQuery::create([
        'user_id' => $this->student->id,
        'image_disk_path' => 'photo-doubts/ready.jpg',
        'status' => 'ready',
        'extracted_question' => 'What is velocity?',
        'solution' => 'Velocity is displacement over time.',
    ]);
    PhotoDoubtQuery::create([
        'user_id' => $this->student->id,
        'image_disk_path' => 'photo-doubts/failed.jpg',
        'status' => 'failed',
    ]);

    $this->actingAs($this->student);

    Livewire::test(PhotoDoubtUpload::class)
        ->assertSee('Processing your photo')
        ->assertSee('What is velocity?')
        ->assertSee('Velocity is displacement over time.')
        ->assertSee('Something went wrong reading that photo');
});

it('submits an image and creates a query, dispatching the processing job', function () {
    Bus::fake();

    $this->actingAs($this->student);

    Livewire::test(PhotoDoubtUpload::class)
        ->set('image', UploadedFile::fake()->image('question.jpg'))
        ->call('submit')
        ->assertHasNoErrors();

    expect(PhotoDoubtQuery::where('user_id', $this->student->id)->where('status', 'pending')->count())->toBe(1);
});

it('adds a validation error for a disallowed file type instead of crashing', function () {
    $this->actingAs($this->student);

    Livewire::test(PhotoDoubtUpload::class)
        ->set('image', UploadedFile::fake()->create('question.pdf', 100))
        ->call('submit')
        ->assertHasErrors('image');
});
