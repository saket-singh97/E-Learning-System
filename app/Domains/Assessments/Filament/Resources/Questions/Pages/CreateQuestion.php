<?php

namespace App\Domains\Assessments\Filament\Resources\Questions\Pages;

use App\Domains\Assessments\Filament\Resources\Questions\Pages\Concerns\NormalizesQuestionFormData;
use App\Domains\Assessments\Filament\Resources\Questions\QuestionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateQuestion extends CreateRecord
{
    use NormalizesQuestionFormData;

    protected static string $resource = QuestionResource::class;

    /**
     * Manual authoring only (I1) — always starts as an unreviewed draft.
     * The approve gate (B3) is the only path that ever flips status.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->normalizeQuestionFormData($data);

        $data['status'] = 'draft';
        $data['source'] = 'manual';
        $data['created_by'] = auth()->id();

        return $data;
    }
}
