<?php

use App\Domains\Assessments\Models\HallTicket;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Growth\Models\ScholarshipTest;
use App\Domains\Growth\Models\ScholarshipTestRegistration;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $section = CourseSection::create(['course_id' => Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid()])->id, 'title' => 'S', 'position' => 0]);
    $quiz = Quiz::create([
        'lesson_id' => Lesson::create(['course_section_id' => $section->id, 'title' => 'L', 'type' => 'quiz', 'position' => 0])->id,
        'title' => 'Q',
        'blueprint' => [],
        'time_limit_minutes' => 60,
        'max_attempts' => 1,
    ]);
    $test = ScholarshipTest::create(['title' => 'T', 'quiz_id' => $quiz->id, 'opens_at' => now(), 'closes_at' => now()->addWeek()]);
    $registration = ScholarshipTestRegistration::create(['scholarship_test_id' => $test->id, 'name' => 'Asha', 'email' => 'asha@example.com']);

    $this->hallTicket = HallTicket::create([
        'scholarship_test_registration_id' => $registration->id,
        'roll_number' => "{$test->id}-0001",
        'pdf_path' => "hall-tickets/{$this->tenant->id}/{$registration->id}.pdf",
    ]);
    Storage::disk('local')->put($this->hallTicket->pdf_path, '%PDF-bytes');
});

it('downloads via a valid signed URL, no auth required', function () {
    $url = URL::signedRoute('hall-tickets.download', [
        'subdomain' => 'physics',
        'hallTicket' => $this->hallTicket->id,
    ]);

    test()->get($url)
        ->assertOk()
        ->assertDownload();
});

it('rejects an unsigned request', function () {
    test()->get("http://physics.lms.test/hall-tickets/{$this->hallTicket->id}/download")
        ->assertForbidden();
});

it('rejects a tampered signature', function () {
    $url = URL::signedRoute('hall-tickets.download', [
        'subdomain' => 'physics',
        'hallTicket' => $this->hallTicket->id,
    ]);

    test()->get($url.'&tampered=1')
        ->assertForbidden();
});

it('404s if the pdf file is missing from disk', function () {
    $this->hallTicket->update(['pdf_path' => 'hall-tickets/missing.pdf']);

    $url = URL::signedRoute('hall-tickets.download', [
        'subdomain' => 'physics',
        'hallTicket' => $this->hallTicket->id,
    ]);

    test()->get($url)->assertNotFound();
});
