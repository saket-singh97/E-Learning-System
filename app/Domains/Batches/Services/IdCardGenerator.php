<?php

namespace App\Domains\Batches\Services;

use App\Domains\Batches\Models\Enrolment;
use App\Domains\Batches\Models\IdCard;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Str;

/**
 * On-demand only (design decision 10) — an Admin/instructor action per
 * enrolment, never auto-issued, so no daily-sweep precedent to follow
 * the way Certificate's own generator has. dompdf + `pdf_disk_path`
 * shape mirrors CertificateService exactly; the QR embed is new — no
 * existing job in this app bakes a QR into a dompdf template (Certificate's
 * own QR only ever renders inline SVG on the verify *web page*, a
 * different context), so it's a base64 PNG data URI here, since dompdf
 * reads `<img>` sources straight off disk/inline rather than fetching a
 * remote URL.
 */
class IdCardGenerator
{
    public function generate(Enrolment $enrolment): IdCard
    {
        $cardNumber = 'ID-'.strtoupper(Str::random(10));
        $diskPath = "id-cards/{$enrolment->tenant_id}/{$enrolment->id}.pdf";

        $qrPng = (new PngWriter())->write(new QrCode(data: $cardNumber))->getString();

        Pdf::loadView('batches.id-card-pdf', [
            'student' => $enrolment->user,
            'batch' => $enrolment->batch,
            'cardNumber' => $cardNumber,
            'qrDataUri' => 'data:image/png;base64,'.base64_encode($qrPng),
            'issuedAt' => now(),
        ])->save($diskPath, 'local');

        return IdCard::create([
            'enrolment_id' => $enrolment->id,
            'card_number' => $cardNumber,
            'disk_path' => $diskPath,
            'issued_at' => now(),
        ]);
    }
}
