<?php

namespace App\Domains\Security\Services;

use App\Domains\Security\Jobs\GenerateGdprExport;
use App\Domains\Security\Models\GdprExportRequest;
use App\Models\User;

/**
 * Design doc §4 decision 7 — the request row + dispatch only; the actual
 * zip-building is `GenerateGdprExport`'s own job, matching
 * `ExportRevenueRegister`'s "ShouldQueue does the slow part" shape (S9
 * B11). `requested_by` is a real, distinct actor from `$subject` — this is
 * a staff-initiated export on behalf of a data subject (schema's own
 * `requested_by_id` column, separate from `user_id`), not a self-service
 * flow.
 */
class GdprExportService
{
    public function request(User $subject, User $requestedBy): GdprExportRequest
    {
        $request = GdprExportRequest::create([
            'tenant_id' => $subject->tenant_id,
            'user_id' => $subject->id,
            'requested_by_id' => $requestedBy->id,
            'status' => GdprExportRequest::STATUS_PENDING,
        ]);

        GenerateGdprExport::dispatch($request->id, $subject->tenant_id);

        return $request;
    }
}
