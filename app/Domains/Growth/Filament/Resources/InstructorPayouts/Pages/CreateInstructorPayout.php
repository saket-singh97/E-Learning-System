<?php

namespace App\Domains\Growth\Filament\Resources\InstructorPayouts\Pages;

use App\Domains\Growth\Filament\Resources\InstructorPayouts\InstructorPayoutResource;
use App\Domains\Growth\Models\InstructorPayout;
use App\Domains\Growth\Services\InstructorPayoutService;
use App\Models\User;
use Carbon\Carbon;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateInstructorPayout extends CreateRecord
{
    protected static string $resource = InstructorPayoutResource::class;

    /**
     * `revenue_share` never lets an admin type `amount` directly — the
     * service computes it from the instructor's own captured payments,
     * same reason `amount` stays a plain form field only for `salaried`.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $instructor = User::findOrFail($data['instructor_id']);
        $periodStart = Carbon::parse($data['period_start']);
        $periodEnd = Carbon::parse($data['period_end']);

        if ($data['basis'] === InstructorPayout::BASIS_REVENUE_SHARE) {
            return app(InstructorPayoutService::class)->createRevenueShare(
                $instructor,
                $periodStart,
                $periodEnd,
                (float) $data['share_percent'],
            );
        }

        return app(InstructorPayoutService::class)->createSalaried(
            $instructor,
            $periodStart,
            $periodEnd,
            (float) $data['amount'],
        );
    }
}
