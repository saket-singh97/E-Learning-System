<?php

namespace App\Domains\Batches\Services;

use App\Domains\Batches\Events\AttendanceRecorded;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\DeviceWebhookCredential;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\AttendanceRecord;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;

/**
 * S16 B4 (H12, design doc §4 decision 11) — a generic, vendor-agnostic
 * biometric/RFID device webhook: `device_webhook_credentials.webhook_secret`
 * + HMAC-SHA256 verification is the entire contract, same signature shape
 * `ZoomRecordingWebhookHandler` (S14 B8) already established. The
 * controller does no business logic (same split as Zoom's own handler) —
 * everything from signature verification onward lives here.
 *
 * `user_identifier` resolves against `User.email`, scoped to the
 * credential's own tenant — this is also what makes a wrong-tenant
 * credential structurally unable to write into another tenant's
 * attendance, with no special-case check needed (build-time call, plan's
 * own §B4 note).
 *
 * The payload carries no session reference (design doc's own minimal
 * contract), so the target `BatchSession` is resolved as the one
 * currently-live session among the batches this credential covers
 * (a specific batch, or every batch the user is actively enrolled in when
 * `batch_id` is null) that the resolved user is actually enrolled in. No
 * live match — event outside class hours, unknown device, etc. — is a
 * silent no-op, not an error; a physical device firing off-hours must not
 * 500.
 */
class DeviceWebhookHandler
{
    public function verifySignature(string $payload, string $timestamp, string $signature, string $secret): bool
    {
        $expected = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$payload}", $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleEvent(DeviceWebhookCredential $credential, array $payload): void
    {
        if (($payload['event'] ?? null) !== 'check_in') {
            return;
        }

        app(CurrentTenant::class)->set(Tenant::findOrFail($credential->tenant_id));

        $user = User::where('tenant_id', $credential->tenant_id)
            ->where('email', (string) ($payload['user_identifier'] ?? ''))
            ->first();

        if ($user === null) {
            return;
        }

        $session = $this->resolveLiveSession($credential, $user);

        if ($session === null) {
            return;
        }

        AttendanceRecord::updateOrCreate(
            [
                'attendable_type' => BatchSession::class,
                'attendable_id' => $session->id,
                'user_id' => $user->id,
            ],
            [
                'role' => 'student',
                'status' => 'present',
                'source' => 'device_webhook',
                'recorded_at' => now(),
            ],
        );

        event(new AttendanceRecorded($user->id, $credential->tenant_id, 'present'));
    }

    protected function resolveLiveSession(DeviceWebhookCredential $credential, User $user): ?BatchSession
    {
        $enrolledBatchIds = Enrolment::where('user_id', $user->id)
            ->where('status', 'active')
            ->pluck('batch_id');

        $eligibleBatchIds = $credential->batch_id !== null
            ? $enrolledBatchIds->filter(fn (int $batchId): bool => $batchId === $credential->batch_id)
            : $enrolledBatchIds;

        if ($eligibleBatchIds->isEmpty()) {
            return null;
        }

        return BatchSession::whereIn('batch_id', $eligibleBatchIds)
            ->get()
            ->first(fn (BatchSession $session): bool => $session->liveState() === BatchSession::STATE_LIVE);
    }
}
