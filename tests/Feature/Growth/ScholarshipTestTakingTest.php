<?php

use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Growth\Livewire\ScholarshipTestRegistrationForm;
use App\Domains\Growth\Models\ScholarshipTest;
use App\Domains\Growth\Models\ScholarshipTestRegistration;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * S23 B20 review-pass fix — independent review found `grade()` had no
 * caller anywhere in the app: the registration flow never actually
 * presented the test's own questions, so U12 shipped as a lead-capture
 * form, not a test. These cover the wiring this block adds, plus the
 * companion security fix (a repeat submission under an already-registered
 * email must never re-expose that registrant's hall ticket or let the
 * submitter answer on their behalf).
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid()]);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Quiz Lesson', 'type' => 'quiz', 'position' => 0]);

    $this->question = Question::create([
        'course_id' => $course->id,
        'type' => 'mcq',
        'stem' => 'What is 2 + 2?',
        'options' => ['3', '4', '5'],
        'correct_answer' => '4',
        'difficulty' => 'easy',
        'status' => 'approved',
        'source' => 'manual',
    ]);

    $this->quiz = Quiz::create([
        'lesson_id' => $lesson->id,
        'title' => 'Scholarship Quiz',
        'blueprint' => [['count' => 1]],
        'time_limit_minutes' => 60,
        'max_attempts' => 1,
    ]);

    $this->test = ScholarshipTest::create([
        'course_id' => $course->id,
        'title' => 'National Scholarship Test',
        'quiz_id' => $this->quiz->id,
        'opens_at' => now()->subDay(),
        'closes_at' => now()->addWeek(),
    ]);

    RateLimiter::clear('scholarship-test-register:127.0.0.1');
});

it('presents the test after registering and scores a submitted answer', function () {
    Livewire::test(ScholarshipTestRegistrationForm::class, ['scholarshipTestId' => $this->test->id])
        ->set('name', 'Guest Prospect')
        ->set('email', 'guest@example.com')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSee($this->question->stem)
        ->set("responses.{$this->question->id}", '4')
        ->call('submitAnswers')
        ->assertSee('answers have been submitted');

    $registration = ScholarshipTestRegistration::sole();
    expect((float) $registration->score)->toBe(1.0);
});

it('does not open the test outside its opens_at/closes_at window', function () {
    $this->test->update(['opens_at' => now()->addDay(), 'closes_at' => now()->addWeek()]);

    Livewire::test(ScholarshipTestRegistrationForm::class, ['scholarshipTestId' => $this->test->id])
        ->set('name', 'Guest Prospect')
        ->set('email', 'guest@example.com')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDontSee($this->question->stem)
        ->assertSee('not currently open');
});

it('ignores a submitted answer for a question outside the drawn set', function () {
    // A different course entirely — never a candidate for this test's own
    // draw (scoped to its quiz's own course), so it can never end up in
    // $questionIds no matter how the random draw among same-course
    // candidates lands.
    $otherCourse = Course::create(['title' => 'Chemistry', 'slug' => 'chemistry-'.uniqid()]);
    $foreignQuestion = Question::create([
        'course_id' => $otherCourse->id,
        'type' => 'mcq',
        'stem' => 'Foreign question',
        'options' => ['A', 'B'],
        'correct_answer' => 'A',
        'difficulty' => 'easy',
        'status' => 'approved',
        'source' => 'manual',
    ]);

    Livewire::test(ScholarshipTestRegistrationForm::class, ['scholarshipTestId' => $this->test->id])
        ->set('name', 'Guest Prospect')
        ->set('email', 'guest@example.com')
        ->call('submit')
        ->set("responses.{$this->question->id}", '3')
        ->set("responses.{$foreignQuestion->id}", 'A')
        ->call('submitAnswers');

    // The drawn set is only the one question; the tampered extra key must
    // not add any score even though it would have graded correctly.
    expect((float) ScholarshipTestRegistration::sole()->score)->toBe(0.0);
});

it('does not re-expose the hall ticket or the test to a repeat submission under an already-registered email', function () {
    Livewire::test(ScholarshipTestRegistrationForm::class, ['scholarshipTestId' => $this->test->id])
        ->set('name', 'Guest Prospect')
        ->set('email', 'guest@example.com')
        ->call('submit');

    $originalRegistration = ScholarshipTestRegistration::sole();
    $originalHallTicketId = $originalRegistration->hallTicket->id;

    Livewire::test(ScholarshipTestRegistrationForm::class, ['scholarshipTestId' => $this->test->id])
        ->set('name', 'Attacker Name')
        ->set('email', 'guest@example.com')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSee('already registered')
        ->assertDontSee('Download your hall ticket')
        ->assertDontSee($this->question->stem);

    // The original registrant's own name/hall ticket are untouched by the
    // repeat submission — no overwrite, no second hall ticket generated.
    expect(ScholarshipTestRegistration::sole()->name)->toBe('Guest Prospect');
    expect(ScholarshipTestRegistration::sole()->hallTicket->id)->toBe($originalHallTicketId);
});

it('refuses a crafted payload that tries to set a locked property directly, not just a forged email', function () {
    // S23 B21 review-pass fix (critical) — Livewire applies a request's
    // `updates` to any public property before invoking the requested
    // method, so gating purely on server-set state (as the B20 fix did)
    // is worthless unless that state is also `#[Locked]`. Without it, a
    // crafted payload could set registrationId/isNewRegistration directly
    // — no email-guessing needed — and reach the exact hijack the B20 fix
    // claims to close.
    expect(fn () => Livewire::test(ScholarshipTestRegistrationForm::class, ['scholarshipTestId' => $this->test->id])
        ->set('registrationId', 999999))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect(fn () => Livewire::test(ScholarshipTestRegistrationForm::class, ['scholarshipTestId' => $this->test->id])
        ->set('isNewRegistration', true))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect(fn () => Livewire::test(ScholarshipTestRegistrationForm::class, ['scholarshipTestId' => $this->test->id])
        ->set('questionIds', [$this->question->id]))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('refuses to grade a submission made after the test has already closed', function () {
    $component = Livewire::test(ScholarshipTestRegistrationForm::class, ['scholarshipTestId' => $this->test->id])
        ->set('name', 'Guest Prospect')
        ->set('email', 'guest@example.com')
        ->call('submit')
        ->assertSee($this->question->stem);

    // The window closes after the form was already loaded — same as a
    // prospect leaving a tab open past the deadline.
    $this->test->update(['closes_at' => now()->subMinute()]);

    $component->set("responses.{$this->question->id}", '4')->call('submitAnswers');

    expect(ScholarshipTestRegistration::sole()->score)->toBeNull();
});

it('rate-limits repeated registration attempts from the same IP', function () {
    for ($i = 0; $i < 10; $i++) {
        Livewire::test(ScholarshipTestRegistrationForm::class, ['scholarshipTestId' => $this->test->id])
            ->set('name', 'Prospect')
            ->set('email', "prospect{$i}@example.com")
            ->call('submit')
            ->assertHasNoErrors();
    }

    Livewire::test(ScholarshipTestRegistrationForm::class, ['scholarshipTestId' => $this->test->id])
        ->set('name', 'Prospect')
        ->set('email', 'prospect-over-limit@example.com')
        ->call('submit')
        ->assertHasErrors('email');

    expect(ScholarshipTestRegistration::where('email', 'prospect-over-limit@example.com')->exists())->toBeFalse();
});
