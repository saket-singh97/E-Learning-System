<?php

namespace App\Domains\Content\Filament\Resources\CourseSections\RelationManagers;

use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Services\RevisionNotesGenerator;
use App\Domains\Content\Events\LessonIngestionRetryRequested;
use App\Domains\Content\Filament\Resources\Lessons\LessonResource;
use App\Domains\Content\Models\Lesson;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class LessonsRelationManager extends RelationManager
{
    protected static string $relationship = 'lessons';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->required()
                ->maxLength(255),
            Select::make('type')
                ->options(array_combine(Lesson::TYPES, Lesson::TYPES))
                ->live()
                ->required(),
            FileUpload::make('video_upload')
                ->label('Video file')
                // live_session lessons attach their recording through this
                // same field after class (S5 B6) — not just video lessons.
                ->visible(fn (Get $get): bool => in_array($get('type'), ['video', 'live_session'], true))
                ->disk('local')
                ->directory('videos/tmp')
                ->acceptedFileTypes(['video/mp4', 'video/webm'])
                ->rules(['mimes:mp4,webm'])
                ->dehydrated(),
            DateTimePicker::make('scheduled_at')
                ->label('Scheduled for')
                ->helperText('Leave blank to make it visible to students immediately.'),
            Toggle::make('is_free_preview')
                ->label('Free preview'),
        ]);
    }

    protected function attachUploadedVideo(Lesson $record, array $data): void
    {
        if (filled($data['video_upload'] ?? null)) {
            $record->attachUploadedVideo($data['video_upload']);
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->reorderable('position')
            ->defaultSort('position')
            ->columns([
                TextColumn::make('title'),
                TextColumn::make('type')->badge(),
                TextColumn::make('scheduled_at')
                    ->label('Scheduled')
                    ->badge()
                    ->color('warning')
                    ->state(fn (Lesson $record): ?string => $record->isScheduledForFuture()
                        ? 'Scheduled: '.$record->scheduled_at->diffForHumans()
                        : null)
                    ->toggleable(),
                IconColumn::make('is_free_preview')
                    ->label('Free preview')
                    ->boolean(),
                LessonResource::aiStatusColumn(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->headerActions([
                // Same tie-break gap as SectionsRelationManager: reorderable()
                // never assigns a position on create, so unreordered lessons
                // all sit at the column's DB default of 0 and CourseSection::
                // lessons()'s orderBy('position') breaks the tie by arbitrary
                // DB order instead of authoring order. Append after the last.
                CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['position'] = (int) Lesson::withoutGlobalScope(SoftDeletingScope::class)
                            ->where('course_section_id', $this->getOwnerRecord()->id)
                            ->max('position') + 1;

                        return $data;
                    })
                    ->after(function (Lesson $record, array $data): void {
                        $this->attachUploadedVideo($record, $data);
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->after(function (Lesson $record, array $data): void {
                        $this->attachUploadedVideo($record, $data);
                    }),
                Action::make('viewAiContent')
                    ->label('AI content')
                    ->visible(fn (Lesson $record): bool => LessonArtifact::latestFor($record->id) !== null)
                    ->url(fn (Lesson $record): string => LessonResource::getUrl('artifact', ['record' => $record])),
                Action::make('retryIngestion')
                    ->label('Retry ingestion')
                    ->requiresConfirmation()
                    ->visible(fn (Lesson $record): bool => LessonArtifact::latestFor($record->id)?->status === 'failed')
                    ->action(fn (Lesson $record) => event(new LessonIngestionRetryRequested($record->id, $record->tenant_id))),
                // S17 B4 (L11) — instructor-triggered, per-lesson, never an
                // automatic sweep (design §4 decision 4). Only enabled once
                // a ready artifact actually exists to summarize.
                Action::make('generateRevisionNotes')
                    ->label('Generate revision notes')
                    ->requiresConfirmation()
                    ->visible(fn (Lesson $record): bool => LessonArtifact::latestReadyFor($record->id) !== null)
                    ->action(function (Lesson $record): void {
                        app(RevisionNotesGenerator::class)->generateFor($record);

                        Notification::make()
                            ->title('Revision notes generated.')
                            ->success()
                            ->send();
                    }),
                DeleteAction::make(),
                ForceDeleteAction::make(),
                RestoreAction::make(),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]));
    }
}
