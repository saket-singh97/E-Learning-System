<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Batches\Models\IdCard;
use App\Domains\Batches\Services\IdCardGenerator;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('generates a PDF and creates the id_cards row', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch A',
        'slug' => 'batch-a',
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $enrolment = Enrolment::create([
        'batch_id' => $batch->id,
        'user_id' => $student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    $idCard = (new IdCardGenerator)->generate($enrolment);

    expect($idCard->enrolment_id)->toBe($enrolment->id);
    expect($idCard->card_number)->toStartWith('ID-');
    expect(Storage::disk('local')->exists($idCard->disk_path))->toBeTrue();
    expect(IdCard::where('tenant_id', $tenant->id)->where('card_number', $idCard->card_number)->exists())->toBeTrue();
});

it('is idempotent — a second generate() call for an already-issued enrolment returns the existing card, not a duplicate', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch A',
        'slug' => 'batch-a',
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $enrolment = Enrolment::create([
        'batch_id' => $batch->id,
        'user_id' => $student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    $generator = new IdCardGenerator;
    $first = $generator->generate($enrolment);
    $second = $generator->generate($enrolment);

    expect($second->id)->toBe($first->id);
    expect($second->card_number)->toBe($first->card_number);
    expect(IdCard::where('enrolment_id', $enrolment->id)->count())->toBe(1);
});
