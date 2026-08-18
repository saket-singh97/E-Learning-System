<?php

namespace App\Domains\Security\Jobs;

use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Assignments\Models\AssignmentSubmission;
use App\Domains\Assignments\Models\Certificate;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Models\FeePayment;
use App\Domains\Security\Models\DeviceSession;
use App\Domains\Security\Models\GdprExportRequest;
use App\Domains\Security\Models\LoginHistory;
use App\Models\User;
use App\Support\Tenancy\RebindsTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

/**
 * Design doc §4 decision 7 — one zip, one CSV per domain, matching
 * `ExportRevenueRegister`'s own `ShouldQueue` + `Storage::disk('local')`
 * shape (S9 B11), not a new pattern. `RebindsTenant` middleware, same as
 * that job — every model read here is `BelongsToTenant`, and a queue
 * worker has no ambient `CurrentTenant` of its own.
 *
 * Covers the concrete examples decision 7 itself names — enrolments,
 * submissions, certificates, fee history, quiz attempts — plus this
 * slice's own new login/device tables (the most obviously personal data
 * collected about a user, having just been built in this very slice).
 * Not an exhaustive sweep of every domain in the app: a real fast-follow
 * if a genuine audit ever demands more, not assumed needed here.
 */
class GenerateGdprExport implements ShouldQueue
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

    public function __construct(
        public readonly int $gdprExportRequestId,
        public readonly int $tenant_id,
    ) {}

    public function middleware(): array
    {
        return [new RebindsTenant];
    }

    public static function pathFor(int $requestId): string
    {
        return "gdpr-exports/{$requestId}.zip";
    }

    public function handle(): void
    {
        $exportRequest = GdprExportRequest::findOrFail($this->gdprExportRequestId);
        $exportRequest->update(['status' => GdprExportRequest::STATUS_PROCESSING]);

        try {
            $path = $this->buildZip($exportRequest);

            $exportRequest->update([
                'status' => GdprExportRequest::STATUS_READY,
                'export_disk_path' => $path,
                'expires_at' => now()->addDays(7),
            ]);
        } catch (Throwable $e) {
            $exportRequest->update(['status' => GdprExportRequest::STATUS_FAILED]);

            throw $e;
        }
    }

    protected function buildZip(GdprExportRequest $exportRequest): string
    {
        $user = User::findOrFail($exportRequest->user_id);
        $disk = Storage::disk('local');
        $disk->makeDirectory('gdpr-exports');

        $relativePath = self::pathFor($exportRequest->id);
        $zip = new ZipArchive;
        $zip->open($disk->path($relativePath), ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('profile.csv', $this->csv(
            ['id', 'name', 'email', 'phone', 'created_at'],
            collect([$user]),
            fn (User $u): array => [$u->id, $u->name, $u->email, $u->phone, $u->created_at?->toDateTimeString()],
        ));

        $zip->addFromString('enrolments.csv', $this->csv(
            ['id', 'batch_id', 'status', 'source', 'requested_at', 'decided_at', 'expires_at'],
            Enrolment::where('user_id', $user->id)->get(),
            fn (Enrolment $e): array => [$e->id, $e->batch_id, $e->status, $e->source, $e->requested_at?->toDateTimeString(), $e->decided_at?->toDateTimeString(), $e->expires_at?->toDateTimeString()],
        ));

        $zip->addFromString('assignment_submissions.csv', $this->csv(
            ['id', 'assignment_id', 'version_number', 'submitted_at', 'is_late', 'marks_awarded', 'graded_at'],
            AssignmentSubmission::where('user_id', $user->id)->get(),
            fn (AssignmentSubmission $s): array => [$s->id, $s->assignment_id, $s->version_number, $s->submitted_at?->toDateTimeString(), $s->is_late ? '1' : '0', $s->marks_awarded, $s->graded_at?->toDateTimeString()],
        ));

        $zip->addFromString('certificates.csv', $this->csv(
            ['id', 'course_id', 'verification_id', 'final_score', 'issued_at'],
            Certificate::where('user_id', $user->id)->get(),
            fn (Certificate $c): array => [$c->id, $c->course_id, $c->verification_id, $c->final_score, $c->issued_at?->toDateTimeString()],
        ));

        $invoices = FeeInvoice::where('user_id', $user->id)->get();

        $zip->addFromString('fee_invoices.csv', $this->csv(
            ['id', 'invoice_number', 'description', 'total_amount', 'status', 'due_date', 'paid_at'],
            $invoices,
            fn (FeeInvoice $i): array => [$i->id, $i->invoice_number, $i->description, $i->total_amount, $i->status, $i->due_date?->toDateString(), $i->paid_at?->toDateTimeString()],
        ));

        $zip->addFromString('fee_payments.csv', $this->csv(
            ['id', 'fee_invoice_id', 'provider', 'amount', 'status'],
            FeePayment::whereIn('fee_invoice_id', $invoices->pluck('id'))->get(),
            fn (FeePayment $p): array => [$p->id, $p->fee_invoice_id, $p->provider, $p->amount, $p->status],
        ));

        $zip->addFromString('quiz_attempts.csv', $this->csv(
            ['id', 'quiz_id', 'status', 'started_at', 'submitted_at', 'score', 'max_score'],
            QuizAttempt::where('user_id', $user->id)->get(),
            fn (QuizAttempt $a): array => [$a->id, $a->quiz_id, $a->status, $a->started_at?->toDateTimeString(), $a->submitted_at?->toDateTimeString(), $a->score, $a->max_score],
        ));

        $zip->addFromString('login_history.csv', $this->csv(
            ['id', 'ip_address', 'country', 'city', 'successful', 'flagged_impossible_travel', 'created_at'],
            LoginHistory::where('user_id', $user->id)->get(),
            fn (LoginHistory $h): array => [$h->id, $h->ip_address, $h->country, $h->city, $h->successful ? '1' : '0', $h->flagged_impossible_travel ? '1' : '0', $h->created_at?->toDateTimeString()],
        ));

        $zip->addFromString('device_sessions.csv', $this->csv(
            ['id', 'device_label', 'ip_address', 'last_seen_at', 'revoked_at'],
            DeviceSession::where('user_id', $user->id)->get(),
            fn (DeviceSession $d): array => [$d->id, $d->device_label, $d->ip_address, $d->last_seen_at?->toDateTimeString(), $d->revoked_at?->toDateTimeString()],
        ));

        $zip->close();

        return $relativePath;
    }

    /**
     * @param  array<int, string>  $header
     * @param  iterable<int, mixed>  $records
     */
    protected function csv(array $header, iterable $records, callable $row): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $header);

        foreach ($records as $record) {
            fputcsv($handle, $row($record));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
