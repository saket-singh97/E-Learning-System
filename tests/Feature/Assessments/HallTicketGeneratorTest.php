<?php

use App\Domains\Assessments\Models\HallTicket;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Services\HallTicketGenerator;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Growth\Models\ScholarshipTest;
use App\Domains\Growth\Models\ScholarshipTestRegistration;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $section = CourseSection::create(['course_id' => Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid()])->id, 'title' => 'S', 'position' => 0]);
    $quiz = Quiz::create([
        'lesson_id' => Lesson::create(['course_section_id' => $section->id, 'title' => 'L', 'type' => 'quiz', 'position' => 0])->id,
        'title' => 'Q',
        'blueprint' => [],
        'time_limit_minutes' => 60,
        'max_attempts' => 1,
    ]);

    $this->test = ScholarshipTest::create([
        'title' => 'National Scholarship Test',
        'quiz_id' => $quiz->id,
        'opens_at' => now(),
        'closes_at' => now()->addWeek(),
    ]);

    $this->generator = new HallTicketGenerator;
});

it('generates a PDF with a QR-baked roll number and a hall_tickets row', function () {
    $registration = ScholarshipTestRegistration::create([
        'scholarship_test_id' => $this->test->id,
        'name' => 'Asha',
        'email' => 'asha@example.com',
    ]);

    $ticket = $this->generator->generate($registration);

    expect($ticket->scholarship_test_registration_id)->toBe($registration->id);
    expect($ticket->roll_number)->toBe("{$this->test->id}-0001");
    expect(Storage::disk('local')->exists($ticket->pdf_path))->toBeTrue();
});

it('assigns sequential roll numbers per test, not globally', function () {
    $otherTest = ScholarshipTest::create([
        'title' => 'Other Test',
        'quiz_id' => $this->test->quiz_id,
        'opens_at' => now(),
        'closes_at' => now()->addWeek(),
    ]);

    $a = ScholarshipTestRegistration::create(['scholarship_test_id' => $this->test->id, 'name' => 'A', 'email' => 'a@example.com']);
    $b = ScholarshipTestRegistration::create(['scholarship_test_id' => $this->test->id, 'name' => 'B', 'email' => 'b@example.com']);
    $c = ScholarshipTestRegistration::create(['scholarship_test_id' => $otherTest->id, 'name' => 'C', 'email' => 'c@example.com']);

    $ticketA = $this->generator->generate($a);
    $ticketB = $this->generator->generate($b);
    $ticketC = $this->generator->generate($c);

    expect($ticketA->roll_number)->toBe("{$this->test->id}-0001");
    expect($ticketB->roll_number)->toBe("{$this->test->id}-0002");
    // A different test's own sequence restarts at 1, not 3.
    expect($ticketC->roll_number)->toBe("{$otherTest->id}-0001");
});

it('regenerating keeps the same roll number, not a duplicate row', function () {
    $registration = ScholarshipTestRegistration::create([
        'scholarship_test_id' => $this->test->id,
        'name' => 'Asha',
        'email' => 'asha@example.com',
    ]);

    $first = $this->generator->generate($registration);
    $second = $this->generator->generate($registration);

    expect($second->id)->toBe($first->id);
    expect($second->roll_number)->toBe($first->roll_number);
    expect(HallTicket::where('scholarship_test_registration_id', $registration->id)->count())->toBe(1);
});
