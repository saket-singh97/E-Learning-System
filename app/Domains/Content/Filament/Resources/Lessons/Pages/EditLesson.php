<?php

namespace App\Domains\Content\Filament\Resources\Lessons\Pages;

use App\Domains\AI\Filament\Resources\CourseOutlineDrafts\CourseOutlineDraftResource;
use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Services\LessonPlanAssistant;
use App\Domains\Content\Events\LessonIngestionRetryRequested;
use App\Domains\Content\Filament\Resources\Lessons\LessonResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditLesson extends EditRecord
{
    protected static string $resource = LessonResource::class;

    /**
     * Captured from the dehydrated form data, not from $this->data. $this->data
     * holds raw component state, where a FileUpload is a uuid-keyed array of
     * TemporaryUploadedFile objects rather than the final disk path string —
     * reading it here handed attachUploadedVideo() an array and 500'd the save.
     */
    protected ?string $uploadedVideoPath = null;

    protected ?string $uploadedPdfPath = null;

    protected ?string $subtitleText = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('aiStatus')
                ->label('AI status: '.(LessonArtifact::latestFor($this->record->id)?->status ?? 'none'))
                ->color('gray')
                ->url(fn (): string => LessonResource::getUrl('artifact', ['record' => $this->record])),
            Action::make('retryIngestion')
                ->label('Retry ingestion')
                ->requiresConfirmation()
                ->visible(fn (): bool => LessonArtifact::latestFor($this->record->id)?->status === 'failed')
                ->action(fn () => event(new LessonIngestionRetryRequested($this->record->id, $this->record->tenant_id))),
            // U8 (S22 B9) — treats this lesson as "the preceding lesson" and
            // drafts a plan for whatever comes next in its own syllabus_map,
            // same header-Action shape ListCourseOutlineDrafts already uses
            // for CourseOutlineGenerator, reusing the same CourseOutlineDraft
            // review queue rather than a second one.
            Action::make('draftNextLessonPlan')
                ->label('Draft next lesson plan')
                ->action(function (): void {
                    $draft = app(LessonPlanAssistant::class)->generateFor($this->record, auth()->user());

                    if ($draft === null) {
                        Notification::make()
                            ->title("Nothing to draft from yet — this lesson's syllabus map hasn't been extracted.")
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Lesson plan drafted — review and approve it below.')
                        ->success()
                        ->send();

                    $this->redirect(CourseOutlineDraftResource::getUrl('edit', ['record' => $draft]));
                }),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->uploadedVideoPath = $data['video_upload'] ?? null;
        $this->uploadedPdfPath = $data['pdf_upload'] ?? null;
        $this->subtitleText = $data['subtitle_text'] ?? null;

        return $data;
    }

    protected function afterSave(): void
    {
        if (filled($this->uploadedVideoPath)) {
            $this->record->attachUploadedVideo($this->uploadedVideoPath);
        }

        if (filled($this->uploadedPdfPath)) {
            $this->record->attachUploadedPdf($this->uploadedPdfPath);
        }

        // filled(), not just !== null: subtitle_text dehydrates on every
        // save regardless of the field's own visible() (pdf/text/embed
        // lessons submit it as an empty string), and an empty save must
        // not overwrite an existing generated/edited VTT file.
        if (filled($this->subtitleText)) {
            $this->record->attachSubtitleText($this->subtitleText);
        }
    }
}
