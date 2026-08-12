<?php

namespace App\Domains\Comms\Filament\Resources\ParentGuardians\Pages;

use App\Domains\Comms\Filament\Resources\ParentGuardians\ParentGuardianResource;
use App\Domains\Comms\Models\ParentGuardianLink;
use App\Domains\Tenancy\Policies\RoleAssignmentPolicy;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateParentGuardian extends CreateRecord
{
    protected static string $resource = ParentGuardianResource::class;

    /**
     * "Create a parent + link ≥1 student in one action" (design doc §2.4
     * decision 2) — the links Repeater isn't a real User column, so it's
     * pulled out here and turned into ParentGuardianLink rows instead of
     * being passed to User::create(). Role assignment goes through
     * RoleAssignmentPolicy::assign(), not syncRoles() directly, so this
     * grant gets the same audit row and hierarchy check every other role
     * grant in this codebase does (found in B1 research: no other resource
     * in this app creates a User from scratch, so there was no existing
     * "just call syncRoles()" shortcut to copy).
     */
    protected function handleRecordCreation(array $data): Model
    {
        $links = $data['links'];
        unset($data['links']);

        return DB::transaction(function () use ($data, $links) {
            $parent = User::create($data);

            app(RoleAssignmentPolicy::class)->assign(auth()->user(), $parent, 'parent');

            foreach ($links as $link) {
                ParentGuardianLink::create([
                    'parent_user_id' => $parent->id,
                    'student_user_id' => $link['student_user_id'],
                    'relationship' => $link['relationship'] ?? null,
                ]);
            }

            return $parent;
        });
    }
}
