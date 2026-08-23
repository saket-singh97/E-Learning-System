<?php

namespace App\Domains\Operations\Jobs;

use App\Domains\Tenancy\Services\InstructorInvitationService;
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
 * First block in the new Operations domain (design doc §3.1) — this
 * bulk job is an operations concern even though it dispatches through
 * Tenancy's own InstructorInvitationService (design decision 8), same
 * "a new domain lands when nothing existing fits" precedent Billing/
 * Security each already established; B13 builds out the rest
 * (Models/Filament/etc. for the admission CRM).
 *
 * Thin wrapper, same posture as BulkEnrolStudents (B11) — one
 * InstructorInvitationService::invite() call per email, not a new
 * invitation code path. invite() has no duplicate-pending-invite guard
 * of its own, so neither does this job (design decision 8: "not a
 * separate bulk-specific invitation code path" — adding one here would
 * be exactly that).
 */
class InviteInstructorsFromEmailList implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<int, string>  $emails
     * @param  array<int, int>  $batchIds
     */
    public function __construct(
        public readonly ?int $tenant_id,
        public readonly array $emails,
        public readonly array $batchIds,
        public readonly int $actorId,
    ) {}

    public function middleware(): array
    {
        return [new RebindsTenant];
    }

    public function handle(): void
    {
        $actor = User::findOrFail($this->actorId);

        foreach ($this->emails as $email) {
            try {
                app(InstructorInvitationService::class)->invite($email, $this->batchIds, $actor);
            } catch (Throwable $e) {
                Log::warning("InviteInstructorsFromEmailList: failed to invite [{$email}]: {$e->getMessage()}");
            }
        }
    }
}
