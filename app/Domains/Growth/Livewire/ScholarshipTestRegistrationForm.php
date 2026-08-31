<?php

namespace App\Domains\Growth\Livewire;

use App\Domains\Assessments\Services\HallTicketGenerator;
use App\Domains\Growth\Models\ScholarshipTest;
use App\Domains\Growth\Models\ScholarshipTestRegistration;
use App\Domains\Growth\Services\ScholarshipTestRegistrationService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\URL;
use Livewire\Component;

/**
 * S23 B12 (U12 pt.1) — public, unauthenticated, same posture
 * CourseInterestForm already established (design doc §4): a prospect isn't
 * a user yet, so there's no auth check here at all.
 */
class ScholarshipTestRegistrationForm extends Component
{
    public int $scholarshipTestId;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public bool $submitted = false;

    public ?int $registrationId = null;

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

        // S23 B17 (U14) — a hall ticket is issued at registration time, not
        // on demand later, since a prospect has no login to come back and
        // trigger generation themselves.
        app(HallTicketGenerator::class)->generate($registration);

        $this->registrationId = $registration->id;
        $this->submitted = true;
    }

    public function render(): View
    {
        $hallTicketDownloadUrl = null;

        if ($this->registrationId !== null) {
            $hallTicket = ScholarshipTestRegistration::findOrFail($this->registrationId)->hallTicket;

            if ($hallTicket !== null) {
                $hallTicketDownloadUrl = URL::signedRoute('hall-tickets.download', [
                    'subdomain' => app(CurrentTenant::class)->get()->subdomain,
                    'hallTicket' => $hallTicket->id,
                ]);
            }
        }

        return view('growth::scholarship-test-registration-form', [
            'hallTicketDownloadUrl' => $hallTicketDownloadUrl,
        ]);
    }
}
