<?php

use App\Domains\Analytics\Models\MonthlyReportCard;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);
    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

function reportCardDownloadUrl(MonthlyReportCard $card, ?\DateTimeInterface $expiry = null): string
{
    return URL::temporarySignedRoute('report-card.download', $expiry ?? now()->addDays(7), [
        'subdomain' => 'physics',
        'monthlyReportCard' => $card->id,
    ]);
}

it('downloads a generated report card via its signed url', function () {
    $card = MonthlyReportCard::create([
        'student_id' => $this->student->id,
        'period_month' => now()->startOfMonth()->toDateString(),
        'status' => 'sent',
        'pdf_disk_path' => 'report-cards/1/1/2026-08.pdf',
    ]);
    Storage::disk('local')->put($card->pdf_disk_path, '%PDF-bytes');

    $this->get(reportCardDownloadUrl($card))->assertOk();
});

it('refuses a tampered or unsigned url', function () {
    $card = MonthlyReportCard::create([
        'student_id' => $this->student->id,
        'period_month' => now()->startOfMonth()->toDateString(),
        'status' => 'sent',
        'pdf_disk_path' => 'report-cards/1/1/2026-08.pdf',
    ]);
    Storage::disk('local')->put($card->pdf_disk_path, '%PDF-bytes');

    $this->get("http://physics.lms.test/report-cards/{$card->id}/download")
        ->assertForbidden();
});

it('404s a card that has not been generated yet', function () {
    $card = MonthlyReportCard::create([
        'student_id' => $this->student->id,
        'period_month' => now()->startOfMonth()->toDateString(),
        'status' => 'pending',
    ]);

    $this->get(reportCardDownloadUrl($card))->assertNotFound();
});
