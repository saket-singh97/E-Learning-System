<?php

namespace App\Domains\Batches\Services;

use App\Domains\Batches\Models\Enrolment;
use App\Domains\Batches\Models\IdCard;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\DB;
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
        // The Filament action's own visible() check (!idCard()->exists())
        // is only a UI hint, not a DB-level guard — two near-simultaneous
        // "Issue ID card" requests (double-click, two open tabs) could
        // otherwise both pass it, each write a distinct card_number to
        // the exact same deterministic $diskPath, and leave the DB row
        // desynced from whichever PDF the second write left on disk.
        // lockForUpdate() serializes concurrent callers on this
        // enrolment; the second one to acquire the lock finds the first
        // one's row already committed and returns it unchanged instead
        // of generating a duplicate.
        return DB::transaction(function () use ($enrolment): IdCard {
            $locked = Enrolment::whereKey($enrolment->id)->lockForUpdate()->firstOrFail();

            $existing = IdCard::where('enrolment_id', $locked->id)->first();

            if ($existing !== null) {
                return $existing;
            }

            $cardNumber = 'ID-'.strtoupper(Str::random(10));
            $diskPath = "id-cards/{$locked->tenant_id}/{$locked->id}.pdf";

            $qrPng = (new PngWriter())->write(new QrCode(data: $cardNumber))->getString();

            Pdf::loadView('batches.id-card-pdf', [
                'student' => $locked->user,
                'batch' => $locked->batch,
                'cardNumber' => $cardNumber,
                'qrDataUri' => 'data:image/png;base64,'.base64_encode($qrPng),
                'issuedAt' => now(),
            ])->save($diskPath, 'local');

            return IdCard::create([
                'enrolment_id' => $locked->id,
                'card_number' => $cardNumber,
                'disk_path' => $diskPath,
                'issued_at' => now(),
            ]);
        });
    }
}
