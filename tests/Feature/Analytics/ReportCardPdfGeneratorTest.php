<?php

use App\Domains\Analytics\Models\MonthlyReportCard;
use App\Domains\Analytics\Services\ReportCardPdfGenerator;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->tenant = Tenant::factory()->create(['name' => 'Acme Institute']);
    app(CurrentTenant::class)->set($this->tenant);
});

function reportCardFixture(Tenant $tenant, string $studentName = 'Ada Lovelace'): array
{
    $student = User::factory()->create(['tenant_id' => $tenant->id, 'name' => $studentName]);
    $card = MonthlyReportCard::create([
        'student_id' => $student->id,
        'period_month' => now()->startOfMonth()->toDateString(),
    ]);

    return [$student, $card];
}

it('generates a real pdf file on disk', function () {
    [, $card] = reportCardFixture($this->tenant);

    $diskPath = app(ReportCardPdfGenerator::class)->generate($card, [
        'attendance_percent' => 82.5,
        'quiz_average_percent' => 70.0,
        'engagement_ratio_percent' => 60.0,
    ]);

    expect(Storage::disk('local')->exists($diskPath))->toBeTrue();
    $bytes = Storage::disk('local')->get($diskPath);
    expect(substr($bytes, 0, 4))->toBe('%PDF');
});

it('renders the students own name, numbers and period into the report card template', function () {
    [$student, $card] = reportCardFixture($this->tenant, 'Ada Lovelace');

    $html = view('analytics.report-card-pdf', [
        'tenant' => $this->tenant,
        'student' => $student,
        'periodMonth' => $card->period_month,
        'summary' => [
            'attendance_percent' => 82.5,
            'quiz_average_percent' => 70.0,
            'engagement_ratio_percent' => 60.0,
        ],
    ])->render();

    expect($html)->toContain('Ada Lovelace')
        ->and($html)->toContain('Acme Institute')
        ->and($html)->toContain('82.5%')
        ->and($html)->toContain('70.0%')
        ->and($html)->toContain('60.0%')
        ->and($html)->toContain($card->period_month->format('F Y'));
});

it('returns a distinct disk path per student for the same period month', function () {
    [, $cardA] = reportCardFixture($this->tenant, 'Student A');
    $studentB = User::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Student B']);
    $cardB = MonthlyReportCard::create([
        'student_id' => $studentB->id,
        'period_month' => now()->startOfMonth()->toDateString(),
    ]);

    $generator = app(ReportCardPdfGenerator::class);
    $zeroSummary = ['attendance_percent' => 0.0, 'quiz_average_percent' => 0.0, 'engagement_ratio_percent' => 0.0];

    $pathA = $generator->generate($cardA, $zeroSummary);
    $pathB = $generator->generate($cardB, $zeroSummary);

    expect($pathA)->not->toBe($pathB);
});
