<?php

namespace App\Domains\Comms\Filament\Resources\ParentGuardians\Pages;

use App\Domains\Comms\Filament\Resources\ParentGuardians\ParentGuardianResource;
use App\Domains\Comms\Models\ParentGuardianLink;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditParentGuardian extends EditRecord
{
    protected static string $resource = ParentGuardianResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /**
     * Fills the links Repeater from the parent's existing
     * ParentGuardianLink rows — mirrored by handleRecordUpdate()'s diff
     * below, the same pair CreateParentGuardian's single handler covers in
     * one pass on create.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['links'] = ParentGuardianLink::where('parent_user_id', $this->record->id)
            ->get(['student_user_id', 'relationship'])
            ->map(fn (ParentGuardianLink $link) => [
                'student_user_id' => $link->student_user_id,
                'relationship' => $link->relationship,
            ])
            ->all();

        return $data;
    }

    /**
     * Diffs the submitted links against the existing rows: removes any
     * link no longer present, upserts the rest. No bulk delete-then-
     * recreate — that would needlessly touch every row's id/timestamps
     * even for a link that didn't change.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $links = $data['links'];
        unset($data['links']);

        $record->update($data);

        $keepStudentIds = collect($links)->pluck('student_user_id')->all();

        ParentGuardianLink::where('parent_user_id', $record->id)
            ->whereNotIn('student_user_id', $keepStudentIds)
            ->delete();

        foreach ($links as $link) {
            ParentGuardianLink::updateOrCreate(
                ['parent_user_id' => $record->id, 'student_user_id' => $link['student_user_id']],
                ['relationship' => $link['relationship'] ?? null],
            );
        }

        return $record;
    }
}
