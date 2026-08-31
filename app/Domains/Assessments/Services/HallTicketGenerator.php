<?php

namespace App\Domains\Assessments\Services;

use App\Domains\Assessments\Models\HallTicket;
use App\Domains\Growth\Models\ScholarshipTest;
use App\Domains\Growth\Models\ScholarshipTestRegistration;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\DB;

/**
 * S23 B17 (U14, design doc §4 decision 8). dompdf + base64 QR PNG, the
 * exact IdCardGenerator shape (design §2.2's own precedent — not
 * Certificate's inline-SVG QR, a different rendering context). One hall
 * ticket per registration, issued once — same idempotent-on-repeat-call
 * posture IdCardGenerator itself takes (unlike RankCardGenerator's
 * overwrite-on-regenerate, B15: a roll number, once printed and carried to
 * an exam hall, must never change under the registrant).
 *
 * Roll number is sequential *per test*, not globally (plan's own B17
 * note) — so the lock has to be coarser than IdCardGenerator's own
 * per-enrolment lock: two registrations of the *same* test generating
 * concurrently would otherwise race on the same next sequence number.
 * This locks the parent ScholarshipTest row (identical "lock the parent
 * that scopes the sequence" idiom RankCardGenerator, B15, already used for
 * TestSeries), which also serializes the existing-row check for any given
 * registration.
 */
class HallTicketGenerator
{
    public function generate(ScholarshipTestRegistration $registration): HallTicket
    {
        return DB::transaction(function () use ($registration): HallTicket {
            $test = ScholarshipTest::whereKey($registration->scholarship_test_id)->lockForUpdate()->firstOrFail();

            $existing = HallTicket::where('scholarship_test_registration_id', $registration->id)->first();

            if ($existing !== null) {
                return $existing;
            }

            $sequence = HallTicket::whereIn(
                'scholarship_test_registration_id',
                ScholarshipTestRegistration::where('scholarship_test_id', $test->id)->pluck('id'),
            )->count() + 1;

            $rollNumber = sprintf('%d-%04d', $test->id, $sequence);
            $diskPath = "hall-tickets/{$registration->tenant_id}/{$registration->id}.pdf";

            $qrPng = (new PngWriter())->write(new QrCode(data: $rollNumber))->getString();

            Pdf::loadView('assessments.hall-ticket-pdf', [
                'registration' => $registration,
                'test' => $test,
                'rollNumber' => $rollNumber,
                'qrDataUri' => 'data:image/png;base64,'.base64_encode($qrPng),
                'issuedAt' => now(),
            ])->save($diskPath, 'local');

            return HallTicket::create([
                'scholarship_test_registration_id' => $registration->id,
                'roll_number' => $rollNumber,
                'pdf_path' => $diskPath,
            ]);
        });
    }
}
