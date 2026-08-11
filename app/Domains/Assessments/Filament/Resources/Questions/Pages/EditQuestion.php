<?php

namespace App\Domains\Assessments\Filament\Resources\Questions\Pages;

use App\Domains\Assessments\Filament\Resources\Questions\Pages\Concerns\NormalizesQuestionFormData;
use App\Domains\Assessments\Filament\Resources\Questions\QuestionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditQuestion extends EditRecord
{
    use NormalizesQuestionFormData;

    protected static string $resource = QuestionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->denormalizeQuestionFormData($data);
    }

    /**
     * L6's gate is "a draft question is never drawable" — editing an
     * already-approved question's content without re-review would let a
     * teacher silently break that invariant (fix a typo, accidentally
     * change the correct answer, never get re-reviewed). Any save on an
     * approved question sends it back to draft rather than trying to
     * distinguish "safe" edits (concept tag) from "unsafe" ones (correct
     * answer) — simpler, and errs toward re-review over trusting the diff.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = $this->normalizeQuestionFormData($data);

        if ($this->record->status === 'approved') {
            $data['status'] = 'draft';
        }

        return $data;
    }
}
