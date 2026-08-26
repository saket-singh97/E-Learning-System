<?php

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\Models\MonthlyReportCard;
use App\Domains\Tenancy\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * A5 pt.2 (design doc §2.3) — reuses barryvdh/laravel-dompdf, the same
 * library CertificateService/IdCardGenerator already use, not a new
 * dependency. Pure rendering step: takes an already-existing tracking row
 * and an already-computed summary (ReportCardDataAggregator's own output,
 * B8), writes the PDF to disk, and returns the path. Persisting that path
 * onto the row and flipping its status is B10's own job (the scheduled
 * sweep that orchestrates aggregate -> generate -> send).
 */
class ReportCardPdfGenerator
{
    public function generate(MonthlyReportCard $card, array $summary): string
    {
        $tenant = Tenant::findOrFail($card->tenant_id);
        $diskPath = "report-cards/{$card->tenant_id}/{$card->student_id}/{$card->period_month->format('Y-m')}.pdf";

        Pdf::loadView('analytics.report-card-pdf', [
            'tenant' => $tenant,
            'student' => $card->student,
            'periodMonth' => $card->period_month,
            'summary' => $summary,
        ])->save($diskPath, 'local');

        return $diskPath;
    }
}
