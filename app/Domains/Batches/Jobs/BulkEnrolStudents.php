<?php

namespace App\Domains\Batches\Jobs;

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Services\BatchEnrolmentService;
use App\Models\User;
use App\Support\Tenancy\RebindsTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin wrapper (design decision 7) — no new enrolment code path, calls
 * BatchEnrolmentService::addManually() once per email exactly like
 * EnrolmentsRelationManager's own "Add student" action already does one
 * row at a time. Existing-students-only: an email that doesn't resolve
 * to a User in this tenant is skipped, not created — that's F9's own
 * job (StudentCsvImporter/ImportStudentsFromCsv), not this one's.
 *
 * addManually() itself has no seat-limit check (confirmed by reading it,
 * not assumed from design decision 7's own "seat lock" phrasing, which
 * describes enrolDirect()/approve() — the staff-add path has always
 * landed active unconditionally, same as the single-row "Add student"
 * action this job is bulk-ifying) — reusing it here inherits that exact
 * existing behavior, not a new gap.
 *
 * No status/error-report tracking (unlike B9/B10's own
 * `student_import_batches`) — this block's own plan text scopes it to
 * just the queued wrapper; a skipped row is logged, not surfaced to an
 * admin-facing report.
 */
class BulkEnrolStudents implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<int, string>  $emails
     */
    public function __construct(
        public readonly ?int $tenant_id,
        public readonly int $batchId,
        public readonly array $emails,
        public readonly int $actorId,
    ) {}

    public function middleware(): array
    {
        return [new RebindsTenant];
    }

    public function handle(): void
    {
        $batch = Batch::findOrFail($this->batchId);
        $actor = User::findOrFail($this->actorId);

        foreach ($this->emails as $email) {
            $student = User::where('email', $email)->where('tenant_id', $this->tenant_id)->first();

            if ($student === null) {
                Log::warning("BulkEnrolStudents: no user found for email [{$email}] in tenant [{$this->tenant_id}], skipped.");

                continue;
            }

            try {
                app(BatchEnrolmentService::class)->addManually($batch, $student, $actor);
            } catch (Throwable $e) {
                Log::warning("BulkEnrolStudents: failed to enrol [{$email}] into batch [{$this->batchId}]: {$e->getMessage()}");
            }
        }
    }
}
