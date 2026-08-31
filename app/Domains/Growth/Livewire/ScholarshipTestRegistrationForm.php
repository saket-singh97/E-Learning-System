<?php

namespace App\Domains\Growth\Livewire;

use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Services\HallTicketGenerator;
use App\Domains\Growth\Models\ScholarshipTest;
use App\Domains\Growth\Models\ScholarshipTestRegistration;
use App\Domains\Growth\Services\ScholarshipTestGradingService;
use App\Domains\Growth\Services\ScholarshipTestRegistrationService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\URL;
use Livewire\Component;

/**
 * S23 B12 (U12 pt.1) — public, unauthenticated, same posture
 * CourseInterestForm already established (design doc §4): a prospect isn't
 * a user yet, so there's no auth check here at all.
 *
 * S23 B20 review-pass fix (security finding): the hall-ticket link and the
 * test itself are shown ONLY when `$isNewRegistration` — i.e. only in the
 * same response that just created the row. There is no auth/OTP for a
 * prospect (design §4 decision 2), so a repeat submit under a *known*
 * email (the registrant's own, or an attacker's guess) can never be
 * distinguished from a genuine owner returning — same "no identity-
 * carrying link across an unauthenticated page boundary" posture B11's
 * `CourseInterestForm::watchDemo()` already established. Without this
 * gate, submitting a victim's already-registered email would silently
 * hand the submitter the victim's hall-ticket download link (name, roll
 * number, QR) and let them answer the test on the victim's behalf.
 */
class ScholarshipTestRegistrationForm extends Component
{
    public int $scholarshipTestId;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public bool $submitted = false;

    public bool $isNewRegistration = false;

    public ?int $registrationId = null;

    /** @var array<int, int> */
    public array $questionIds = [];

    /** @var array<int, mixed> */
    public array $responses = [];

    public bool $answersSubmitted = false;

    public function mount(int $scholarshipTestId): void
    {
        $this->scholarshipTestId = $scholarshipTestId;
    }

    public function submit(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'phone' => 'nullable|string|max:30',
        ]);

        $test = ScholarshipTest::findOrFail($this->scholarshipTestId);

        $registration = app(ScholarshipTestRegistrationService::class)->register(
            $test,
            $this->name,
            $this->email,
            $this->phone !== '' ? $this->phone : null,
        );

        $this->registrationId = $registration->id;
        $this->isNewRegistration = $registration->wasRecentlyCreated;
        $this->submitted = true;

        if (! $this->isNewRegistration) {
            return;
        }

        // S23 B17 (U14) — a hall ticket is issued at registration time, not
        // on demand later, since a prospect has no login to come back and
        // trigger generation themselves.
        app(HallTicketGenerator::class)->generate($registration);

        // S23 B20 review-pass fix (correctness finding): grade() previously
        // had no caller anywhere in the app — the test itself was never
        // actually presented. Drawn once here and kept in Livewire's own
        // component state, not re-drawn on every re-render.
        $this->questionIds = app(ScholarshipTestGradingService::class)->drawQuestions($test);
    }

    public function submitAnswers(): void
    {
        if (! $this->isNewRegistration || $this->registrationId === null || $this->answersSubmitted) {
            return;
        }

        $registration = ScholarshipTestRegistration::findOrFail($this->registrationId);

        // Only the questions actually drawn for this registration are
        // gradable — a tampered payload adding extra question_id keys for
        // any other approved course question must not inflate the score
        // past what this one test actually offered.
        $answers = array_intersect_key($this->responses, array_flip($this->questionIds));

        app(ScholarshipTestGradingService::class)->grade($registration, $answers);

        $this->answersSubmitted = true;
    }

    public function render(): View
    {
        $hallTicketDownloadUrl = null;
        $questions = collect();
        $test = null;

        if ($this->isNewRegistration) {
            $test = ScholarshipTest::findOrFail($this->scholarshipTestId);

            $hallTicket = ScholarshipTestRegistration::findOrFail($this->registrationId)->hallTicket;

            if ($hallTicket !== null) {
                $hallTicketDownloadUrl = URL::signedRoute('hall-tickets.download', [
                    'subdomain' => app(CurrentTenant::class)->get()->subdomain,
                    'hallTicket' => $hallTicket->id,
                ]);
            }

            $withinWindow = now()->between($test->opens_at, $test->closes_at);

            if (! $this->answersSubmitted && $withinWindow && $this->questionIds !== []) {
                $questions = Question::whereIn('id', $this->questionIds)->get();
            }
        }

        return view('growth::scholarship-test-registration-form', [
            'hallTicketDownloadUrl' => $hallTicketDownloadUrl,
            'test' => $test,
            'questions' => $questions,
        ]);
    }
}
