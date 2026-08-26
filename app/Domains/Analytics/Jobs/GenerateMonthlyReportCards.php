<?php

namespace App\Domains\Analytics\Jobs;

use App\Domains\Analytics\Models\MonthlyReportCard;
use App\Domains\Analytics\Services\ReportCardDataAggregator;
use App\Domains\Analytics\Services\ReportCardPdfGenerator;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Comms\Models\ParentGuardianLink;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Notifications\MonthlyReportCardReady;
use App\Support\Tenancy\CurrentTenant;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * A5 pt.3 (S18 B10, design doc §2.1/§4 decision 8) — monthly sweep: one
 * MonthlyReportCard row per active student for the calendar month that just
 * ended, generated (B8 aggregator + B9 PDF) and delivered to any linked
 * parent (ParentGuardianLink — same lookup SendAbsenceAlert already uses
 * for a parent-facing signal) as a signed download link over WhatsApp
 * (design doc §4 decision 3 — no media-attachment CommsProvider method
 * exists, so the PDF itself never leaves disk).
 *
 * Idempotent per design doc §4 decision 6: a row already `generated` or
 * `sent` for this period_month is left untouched on a re-run — a scheduler
 * retry or manual re-trigger can never double-send a parent the same
 * month's report. A `pending`/`failed` row (an interrupted or failed prior
 * attempt) is retried instead of skipped, same posture this job's own
 * tries/backoff already assumes.
 *
 * Iterates every active Enrolment (not a distinct student query) same as
 * SendWeakTopicPracticeNudges — a student active in more than one batch is
 * simply visited twice, and the idempotency check above makes the second
 * visit a no-op.
 *
 * CurrentTenant cleared up front, same unscoped cross-tenant sweep shape as
 * every other S9 B10-converted job — tenant_id is read off each Enrolment
 * row instead, and MonthlyReportCard::create() wrapped in withoutEvents()
 * (same shape TeacherAttendanceSweep/RecordVideoCoverageAttendance already
 * use) since BelongsToTenant's creating() hook would otherwise read the
 * now-cleared CurrentTenant and throw.
 */
class GenerateMonthlyReportCards implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(ReportCardDataAggregator $aggregator, ReportCardPdfGenerator $pdfGenerator): void
    {
        app(CurrentTenant::class)->clear();

        $periodMonth = now()->startOfMonth()->subMonth();

        Enrolment::where('status', 'active')
            ->chunk(200, function (Collection $enrolments) use ($periodMonth, $aggregator, $pdfGenerator): void {
                foreach ($enrolments as $enrolment) {
                    $this->processStudent($enrolment->tenant_id, $enrolment->user_id, $periodMonth, $aggregator, $pdfGenerator);
                }
            });
    }

    protected function processStudent(int $tenantId, int $studentId, CarbonInterface $periodMonth, ReportCardDataAggregator $aggregator, ReportCardPdfGenerator $pdfGenerator): void
    {
        $card = MonthlyReportCard::where('student_id', $studentId)
            ->where('period_month', $periodMonth->toDateString())
            ->first();

        if ($card !== null && in_array($card->status, ['generated', 'sent'], true)) {
            return;
        }

        if ($card === null) {
            try {
                MonthlyReportCard::withoutEvents(function () use (&$card, $tenantId, $studentId, $periodMonth): void {
                    $card = MonthlyReportCard::create([
                        'tenant_id' => $tenantId,
                        'student_id' => $studentId,
                        'period_month' => $periodMonth->toDateString(),
                        'status' => 'pending',
                    ]);
                });
            } catch (QueryException $e) {
                // B12 review-pass fix: a manual re-trigger overlapping the
                // scheduled run (or two workers) can both see $card === null
                // before either INSERT commits — the unique(student_id,
                // period_month) constraint then throws for the loser instead
                // of silently losing the race. Re-fetch the row the winner
                // created rather than letting this abort the whole sweep.
                $card = MonthlyReportCard::where('student_id', $studentId)
                    ->where('period_month', $periodMonth->toDateString())
                    ->first();

                if ($card === null) {
                    throw $e;
                }

                if (in_array($card->status, ['generated', 'sent'], true)) {
                    return;
                }
            }
        }

        $student = User::find($studentId);

        if ($student === null) {
            return;
        }

        try {
            $summary = $aggregator->summarize($student, $periodMonth);
            $diskPath = $pdfGenerator->generate($card, $summary);
            $card->forceFill(['pdf_disk_path' => $diskPath, 'status' => 'generated'])->save();

            $this->notifyParents($card, $tenantId, $student);
        } catch (Throwable $e) {
            $card->forceFill(['status' => 'failed'])->save();

            throw $e;
        }
    }

    protected function notifyParents(MonthlyReportCard $card, int $tenantId, User $student): void
    {
        $parents = ParentGuardianLink::where('student_user_id', $student->id)
            ->with('parentUser')
            ->get()
            ->pluck('parentUser')
            ->filter();

        if ($parents->isEmpty()) {
            return;
        }

        $tenant = Tenant::findOrFail($tenantId);

        $downloadUrl = URL::temporarySignedRoute('report-card.download', now()->addDays(7), [
            'subdomain' => $tenant->subdomain,
            'monthlyReportCard' => $card->id,
        ]);

        foreach ($parents as $parent) {
            if ($this->alreadyNotified($parent, $card)) {
                continue;
            }

            $parent->notify(new MonthlyReportCardReady($card, $student, $downloadUrl));
        }

        $card->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
    }

    /**
     * B12 review-pass fix: status only flips to `sent` after every parent in
     * the loop above is notified, so a retry after a partial failure (the
     * 2nd of 3 parents' send throws, or the loop throws for any other
     * reason) previously re-entered this method from scratch and re-sent
     * WhatsApp to parents who already got it — the exact "why did I get
     * this twice" class design doc §5 flags for A5. Same dedupe shape
     * SendAbsenceAlert already uses: read back the notifiable's own stored
     * notifications rather than a second tracking column.
     */
    protected function alreadyNotified(User $parent, MonthlyReportCard $card): bool
    {
        return $parent->notifications()
            ->where('type', MonthlyReportCardReady::class)
            ->get()
            ->contains(fn ($notification) => (int) ($notification->data['monthly_report_card_id'] ?? 0) === $card->id);
    }
}
