<?php

namespace App\Domains\Operations\Services;

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Batches\Services\BatchEnrolmentService;
use App\Domains\Operations\Models\Enquiry;
use App\Domains\Tenancy\Policies\RoleAssignmentPolicy;
use App\Exceptions\EnquiryConversionException;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Str;

/**
 * Writes a real Enrolment through BatchEnrolmentService, not a parallel
 * enrolment path (design decision 9) — an admissions counselor's
 * "convert" action is enrolment staff-adding a student, not a new
 * mechanism. converted_enrolment_id is set from addManually()'s own
 * return value.
 */
class EnquiryConversionService
{
    public function convert(Enquiry $enquiry, Batch $batch, User $actor): Enrolment
    {
        if ($enquiry->status === 'converted') {
            throw EnquiryConversionException::alreadyConverted($enquiry->id);
        }

        $user = User::where('email', $enquiry->email)->first();

        if ($user === null) {
            $user = User::create([
                'tenant_id' => app(CurrentTenant::class)->id(),
                'name' => $enquiry->name,
                'email' => $enquiry->email,
                'phone' => $enquiry->phone,
                'password' => Str::random(32),
                'is_active' => true,
            ]);

            app(RoleAssignmentPolicy::class)->assign($actor, $user, 'student');
        } elseif ($user->tenant_id !== $enquiry->tenant_id) {
            throw EnquiryConversionException::emailUnderDifferentTenant($enquiry->email);
        }

        $enrolment = app(BatchEnrolmentService::class)->addManually($batch, $user, $actor);

        $enquiry->update([
            'status' => 'converted',
            'converted_enrolment_id' => $enrolment->id,
        ]);

        return $enrolment;
    }
}
