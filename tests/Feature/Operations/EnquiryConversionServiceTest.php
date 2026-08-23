<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Operations\Models\Enquiry;
use App\Domains\Operations\Services\EnquiryConversionService;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\EnquiryConversionException;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

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

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin->assignRole('institute_admin');

    $this->service = new EnquiryConversionService;
});

it('creates a new student, enrols them, and marks the enquiry converted', function () {
    $enquiry = Enquiry::create(['name' => 'Priya Sharma', 'email' => 'priya@example.com', 'status' => 'new']);

    $enrolment = $this->service->convert($enquiry, $this->batch, $this->admin);

    $student = User::where('email', 'priya@example.com')->firstOrFail();
    expect($student->hasRole('student'))->toBeTrue();
    expect(Enrolment::where('batch_id', $this->batch->id)->where('user_id', $student->id)->exists())->toBeTrue();

    expect($enquiry->fresh()->status)->toBe('converted');
    expect($enquiry->fresh()->converted_enrolment_id)->toBe($enrolment->id);
});

it('links an existing same-tenant user instead of duplicating', function () {
    $existing = User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'priya@example.com']);
    $enquiry = Enquiry::create(['name' => 'Priya Sharma', 'email' => 'priya@example.com', 'status' => 'new']);

    $this->service->convert($enquiry, $this->batch, $this->admin);

    expect(User::where('email', 'priya@example.com')->count())->toBe(1);
    expect(Enrolment::where('batch_id', $this->batch->id)->where('user_id', $existing->id)->exists())->toBeTrue();
});

it('refuses to convert when the email is already registered under a different tenant', function () {
    $otherTenant = Tenant::factory()->create();
    User::factory()->create(['tenant_id' => $otherTenant->id, 'email' => 'priya@example.com']);
    $enquiry = Enquiry::create(['name' => 'Priya Sharma', 'email' => 'priya@example.com', 'status' => 'new']);

    expect(fn () => $this->service->convert($enquiry, $this->batch, $this->admin))
        ->toThrow(EnquiryConversionException::class);

    expect($enquiry->fresh()->status)->toBe('new');
});

it('refuses to convert an already-converted enquiry', function () {
    $enquiry = Enquiry::create(['name' => 'Priya Sharma', 'email' => 'priya@example.com', 'status' => 'converted']);

    expect(fn () => $this->service->convert($enquiry, $this->batch, $this->admin))
        ->toThrow(EnquiryConversionException::class);
});
