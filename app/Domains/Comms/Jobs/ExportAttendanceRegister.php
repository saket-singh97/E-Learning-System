<?php

namespace App\Domains\Comms\Jobs;

use App\Domains\Batches\Models\BatchSession;
use App\Domains\Comms\Jobs\Concerns\EscapesCsvFormulas;
use App\Domains\Content\Models\AttendanceRecord;
use App\Domains\Content\Models\Lesson;
use App\Models\User;
use App\Support\Tenancy\RebindsTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * This app's T2 job-queue precedent (docs/FEATURES.md) is the AI
 * ingestion chain (TranscribeLesson et al.) — the plan doc's own B6 note
 * also names "quiz auto-submit, fee reminders" as running the same way,
 * but neither implements ShouldQueue; both are plain classes run
 * synchronously by the scheduler (bootstrap/app.php), confirmed by
 * reading every scheduled job class before assuming otherwise. This job
 * follows the AI chain's actual shape instead: nullable `tenant_id`
 * constructor property + RebindsTenant job middleware, not an inline
 * CurrentTenant::set() call.
 *
 * Writes to the `local` disk (storage_path('app/private'), not web-
 * accessible) rather than `public` — this CSV carries student names and
 * emails, the same reasoning FakeVideoDriver/Lesson video assets already
 * use `local` for. DownloadAttendanceExportController is the only way to
 * read it back out, gated the same way DownloadInvoiceController gates
 * FeeInvoice access (S7 B7).
 */
class ExportAttendanceRegister implements ShouldQueue
{
    use Dispatchable, EscapesCsvFormulas, InteractsWithQueue, Queueable, SerializesModels;

    // S9 B10 — the one job in the "every existing job gets tries/backoff()"
    // list that actually benefits from Laravel's own automatic queue retry
    // with no extra work: already ShouldQueue, already tenant-scoped via
    // RebindsTenant, and handle() has no internal try/catch (unlike the AI
    // ingestion chain — see TranscribeLesson's own docblock for why theirs
    // don't get this). Idempotent by construction: a re-run overwrites the
    // same deterministic pathFor($tenantId), no duplicate rows anywhere.
    public int $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(
        public readonly ?int $tenant_id,
    ) {}

    public function middleware(): array
    {
        return [new RebindsTenant];
    }

    public static function pathFor(int $tenantId): string
    {
        return "attendance-exports/{$tenantId}.csv";
    }

    public function handle(): void
    {
        Storage::disk('local')->put(self::pathFor($this->tenant_id), $this->buildCsv());
    }

    protected function buildCsv(): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['Student name', 'Student email', 'Context', 'Status', 'Source', 'Recorded at']);

        AttendanceRecord::orderBy('recorded_at')
            ->chunk(200, function ($records) use ($handle): void {
                foreach ($records as $record) {
                    $student = User::find($record->user_id);

                    fputcsv($handle, [
                        $this->csvSafe($student?->name ?? 'Unknown'),
                        $this->csvSafe($student?->email ?? ''),
                        $this->csvSafe($this->attendableLabel($record)),
                        $record->status,
                        $record->source,
                        $record->recorded_at?->toDateTimeString(),
                    ]);
                }
            });

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * No shared label/title contract exists between Lesson and
     * BatchSession (both happen to have a `title` column, but that's
     * coincidence, not an interface) — confirmed nothing reusable exists
     * for this lookup before writing it here.
     */
    protected function attendableLabel(AttendanceRecord $record): string
    {
        return match ($record->attendable_type) {
            BatchSession::class => 'Live session: '.$this->batchSessionLabel($record->attendable_id),
            Lesson::class => 'Video coverage: '.(Lesson::find($record->attendable_id)?->title ?? 'untitled'),
            default => $record->attendable_type,
        };
    }

    /**
     * A BatchSession's own `title` is frequently blank (the table
     * resource shows "Untitled session" as its own placeholder) — the
     * linked lesson's title (the live-class topic,
     * BatchSessionsRelationManager's own "Live topic" column) is what a
     * reader actually wants here, so it's preferred when present.
     */
    protected function batchSessionLabel(int $attendableId): string
    {
        $session = BatchSession::find($attendableId);

        if ($session === null) {
            return 'untitled';
        }

        return $session->title ?? $session->lesson?->title ?? 'untitled';
    }
}
