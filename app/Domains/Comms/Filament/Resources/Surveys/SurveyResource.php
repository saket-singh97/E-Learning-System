<?php

namespace App\Domains\Comms\Filament\Resources\Surveys;

use App\Domains\Comms\Filament\Resources\Surveys\Pages\CreateSurvey;
use App\Domains\Comms\Filament\Resources\Surveys\Pages\EditSurvey;
use App\Domains\Comms\Filament\Resources\Surveys\Pages\ListSurveys;
use App\Domains\Comms\Jobs\ExportSurveyResponsesCsv;
use App\Domains\Comms\Models\Survey;
use App\Domains\Comms\Models\SurveyQuestion;
use App\Domains\Comms\Services\SurveyResponseAggregator;
use App\Support\Tenancy\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

/**
 * S19 N15 pt.1 — institute-authored bundle CRUD, same posture
 * `ProgramResource`/`TestSeriesResource` already established. Comms'
 * resource directory is discovered on the Admin panel (unlike Assessments,
 * Teach-panel-only by default), so no explicit `->resources()` entry is
 * needed on `AdminPanelProvider` the way `TestSeriesResource` required.
 *
 * Questions are authored inline via a `Repeater` bound to the
 * `questions` relationship, not a separate child resource+relation-manager
 * the way `Question`/`Quiz` need — a survey question has none of a real
 * quiz question's grading complexity, so one form covers the whole
 * authoring flow. `orderColumn('position')` handles the `position` column
 * on its own; no manual reorder code needed.
 */
class SurveyResource extends Resource
{
    protected static ?string $model = Survey::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->required()
                ->maxLength(255),
            Textarea::make('description')
                ->maxLength(2000),
            Select::make('status')
                ->options(array_combine(Survey::STATUSES, Survey::STATUSES))
                ->required()
                ->default('draft')
                ->native(false)
                ->helperText('Only a published survey is offered to respondents.'),
            Repeater::make('questions')
                ->relationship()
                ->schema([
                    TextInput::make('prompt')
                        ->required()
                        ->maxLength(500),
                    Select::make('type')
                        ->options(array_combine(SurveyQuestion::TYPES, SurveyQuestion::TYPES))
                        ->required()
                        ->live()
                        ->native(false),
                    TagsInput::make('options.choices')
                        ->label('Choices')
                        ->helperText('Add at least 2 choices.')
                        ->visible(fn (Get $get): bool => in_array($get('type'), ['single_choice', 'multi_choice'], true))
                        ->required(fn (Get $get): bool => in_array($get('type'), ['single_choice', 'multi_choice'], true))
                        ->rules(['min:2']),
                    TextInput::make('options.max')
                        ->label('Max rating')
                        ->numeric()
                        ->default(5)
                        ->visible(fn (Get $get): bool => $get('type') === 'rating'),
                ])
                ->orderColumn('position')
                ->reorderable()
                ->addActionLabel('Add question')
                ->defaultItems(0)
                ->collapsible(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'published' => 'success',
                        'closed' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('questions_count')->counts('questions')->label('Questions'),
                TextColumn::make('creator.name')->label('Author'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                // S19 N15 pt.3 — same "computed at read time" posture the
                // design doc's other read-heavy aggregates already use, and
                // the same modal-action-with-Placeholder idiom
                // FlaggedQuizAttemptResource (S16) already established.
                Action::make('viewResults')
                    ->label('View results')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema(fn (Survey $record): array => collect(app(SurveyResponseAggregator::class)->aggregateFor($record))
                        ->map(fn (array $row) => Placeholder::make("summary_{$row['question']->id}")
                            ->label($row['question']->prompt)
                            ->content(match ($row['question']->type) {
                                'single_choice', 'multi_choice' => collect($row['summary'])
                                    ->map(fn (int $count, string $choice): string => "{$choice}: {$count}")
                                    ->implode(', ') ?: 'No choices configured.',
                                'rating' => $row['summary']['average'] === null
                                    ? 'No ratings yet.'
                                    : "Average {$row['summary']['average']} ({$row['summary']['responses']} responses)",
                                default => "{$row['summary']['responses']} responses",
                            }))
                        ->all()),
                Action::make('exportCsv')
                    ->label('Export CSV')
                    ->action(function (Survey $record): void {
                        ExportSurveyResponsesCsv::dispatch($record->id, app(CurrentTenant::class)->id());

                        Notification::make()
                            ->title('Export queued — reopen this list in a moment to download it.')
                            ->success()
                            ->send();
                    }),
                Action::make('downloadCsv')
                    ->label('Download CSV')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->url(fn (Survey $record): string => route('comms.survey-export.download', [
                        'subdomain' => app(CurrentTenant::class)->get()->subdomain,
                        'survey' => $record->id,
                    ]))
                    ->openUrlInNewTab()
                    ->visible(fn (Survey $record): bool => Storage::disk('local')->exists(
                        ExportSurveyResponsesCsv::pathFor($record->id)
                    )),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSurveys::route('/'),
            'create' => CreateSurvey::route('/create'),
            'edit' => EditSurvey::route('/{record}/edit'),
        ];
    }
}
