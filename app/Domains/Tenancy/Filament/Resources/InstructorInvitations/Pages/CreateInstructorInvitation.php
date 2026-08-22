<?php

namespace App\Domains\Tenancy\Filament\Resources\InstructorInvitations\Pages;

use App\Domains\Tenancy\Filament\Resources\InstructorInvitations\InstructorInvitationResource;
use App\Domains\Tenancy\Services\InstructorInvitationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateInstructorInvitation extends CreateRecord
{
    protected static string $resource = InstructorInvitationResource::class;

    /**
     * Goes through InstructorInvitationService::invite(), not a plain
     * InstructorInvitation::create() — same "one shared path" reason
     * CreateParentGuardian calls RoleAssignmentPolicy::assign() instead of
     * syncRoles() directly: this is also the only call site that generates
     * the token and dispatches the invite email.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $batchIds = array_map('intval', $data['batch_ids'] ?? []);

        return app(InstructorInvitationService::class)->invite($data['email'], $batchIds, auth()->user());
    }
}
