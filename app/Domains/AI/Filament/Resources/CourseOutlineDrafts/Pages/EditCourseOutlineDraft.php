<?php

namespace App\Domains\AI\Filament\Resources\CourseOutlineDrafts\Pages;

use App\Domains\AI\Filament\Resources\CourseOutlineDrafts\CourseOutlineDraftResource;
use App\Domains\AI\Services\CourseOutlineApprovalService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCourseOutlineDraft extends EditRecord
{
    protected static string $resource = CourseOutlineDraftResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Approve')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record->status === 'draft')
                ->action(function (): void {
                    // Persist any in-progress edits to the outline before
                    // approval reads generated_outline to build the real
                    // Course/CourseSection/Lesson rows.
                    $this->save();

                    app(CourseOutlineApprovalService::class)->approve($this->record);

                    $this->fillForm();
                }),
            Action::make('reject')
                ->label('Reject')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record->status === 'draft')
                ->action(function (): void {
                    app(CourseOutlineApprovalService::class)->reject($this->record);

                    $this->fillForm();
                }),
            DeleteAction::make(),
        ];
    }
}
