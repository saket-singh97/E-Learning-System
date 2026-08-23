<?php

namespace App\Domains\Batches\Jobs;

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\StudentImportBatch;
use App\Domains\Batches\Services\BatchEnrolmentService;
use App\Domains\Comms\Jobs\Concerns\EscapesCsvFormulas;
use App\Domains\Tenancy\Policies\RoleAssignmentPolicy;
use App\Models\User;
use App\Support\Tenancy\RebindsTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Phase 2 (design decision 6) — same tenant-rebind/disk-write shape
 * ExportAttendanceRegister already established (bare `tenant_id`
 * constructor property, RebindsTenant middleware, deterministic
 * `Storage::disk('local')` path). Unlike that job, this one is not a
 * blind-retry-safe idempotent overwrite — a re-run would create
 * duplicate Users/Enrolments — so each row is wrapped in its own
 * try/catch rather than leaning on Laravel's automatic queue retry, and
 * `BatchEnrolmentService::addManually()` already absorbs the one
 * duplicate case (an existing Enrolment for the same batch+user) that
 * could otherwise repeat.
 *
 * `$rows` only ever holds StudentCsvImporter::parse()'s own "valid" rows
 * (already past file-shape + per-row validation) — `$initialErrors` is
 * that same call's "errors" array, carried through so the one downloadable
 * report covers every row that never became a real enrolment, not just
 * the ones that failed in this phase.
 */
class ImportStudentsFromCsv implements ShouldQueue
{
    use Dispatchable, EscapesCsvFormulas, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<int, array{row: int, name: string, email: string, phone: ?string, batch_id: int}>  $rows
     * @param  array<int, array{row: int, message: string}>  $initialErrors
     */
    public function __construct(
        public readonly ?int $tenant_id,
        public readonly int $importBatchId,
        public readonly array $rows,
        public readonly array $initialErrors,
    ) {}

    public function middleware(): array
    {
        return [new RebindsTenant];
    }

    public static function pathFor(int $importBatchId): string
    {
        return "student-imports/{$importBatchId}-errors.csv";
    }

    public function handle(): void
    {
        $importBatch = StudentImportBatch::findOrFail($this->importBatchId);
        $importBatch->update(['status' => 'processing']);

        $actor = User::findOrFail($importBatch->uploaded_by_user_id);

        $successCount = 0;
        $rowErrors = $this->initialErrors;

        foreach ($this->rows as $row) {
            try {
                $batch = Batch::findOrFail($row['batch_id']);
                $user = $this->findOrCreateStudent($row, $actor);

                app(BatchEnrolmentService::class)->addManually($batch, $user, $actor);

                $successCount++;
            } catch (Throwable $e) {
                $rowErrors[] = ['row' => $row['row'], 'message' => $e->getMessage()];
            }
        }

        usort($rowErrors, fn (array $a, array $b): int => $a['row'] <=> $b['row']);

        $reportPath = self::pathFor($importBatch->id);
        Storage::disk('local')->put($reportPath, $this->buildErrorReportCsv($rowErrors));

        $importBatch->update([
            'status' => 'done',
            'success_count' => $successCount,
            'error_count' => count($rowErrors),
            'error_report_disk_path' => $reportPath,
        ]);
    }

    /**
     * users.email is globally unique (confirmed by S15 B1's own
     * InstructorInvitationService) — an existing row under a *different*
     * tenant means this email is already spoken for elsewhere. A match
     * in the *same* tenant is reused as-is, no role re-assignment (an
     * existing instructor/admin invited as a "student" here shouldn't be
     * demoted); only a genuinely new User gets the student role granted
     * through RoleAssignmentPolicy, the one chokepoint every real role
     * grant in this app already passes through.
     */
    protected function findOrCreateStudent(array $row, User $actor): User
    {
        $existing = User::where('email', $row['email'])->first();

        if ($existing !== null) {
            if ($existing->tenant_id !== $this->tenant_id) {
                throw new RuntimeException("Email [{$row['email']}] is already registered under a different institute.");
            }

            return $existing;
        }

        $user = User::create([
            'tenant_id' => $this->tenant_id,
            'name' => $row['name'],
            'email' => $row['email'],
            'phone' => $row['phone'],
            'password' => Str::random(32),
            'is_active' => true,
        ]);

        app(RoleAssignmentPolicy::class)->assign($actor, $user, 'student');

        return $user;
    }

    /**
     * @param  array<int, array{row: int, message: string}>  $rowErrors
     */
    protected function buildErrorReportCsv(array $rowErrors): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['Row', 'Error']);

        foreach ($rowErrors as $error) {
            fputcsv($handle, [$error['row'], $this->csvSafe($error['message'])]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
