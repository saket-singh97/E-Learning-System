<?php

namespace App\Domains\Assignments\Filament\Resources\AssignmentSubmissions;

use App\Domains\Assignments\Filament\Resources\AssignmentSubmissions\Pages\ListAssignmentSubmissions;
use App\Domains\Assignments\Models\AssignmentSubmission;
use App\Domains\Assignments\Services\AssignmentGradingService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * J3's grading queue — same read-only-except-one-action shape
 * QuizAnswerResource (S6 B6) already established: no instructor-course
 * pivot exists anywhere in this app (HANDOVER's own "Avoid" section, S4/S8),
 * so this scopes by tenant like every other /teach surface, not by
 * "assigned instructor."
 */
class AssignmentSubmissionResource extends Resource
{
    protected static ?string $model = AssignmentSubmission::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $navigationLabel = 'Grading queue';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('assignment.title')->label('Assignment'),
                TextColumn::make('user.name')->label('Student'),
                TextColumn::make('version_number')->label('Version'),
                TextColumn::make('submitted_at')->dateTime()->sortable(),
                TextColumn::make('is_late')
                    ->label('Late')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'danger' : 'gray')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Late' : 'On time'),
                TextColumn::make('marks_awarded')
                    ->label('Marks')
                    ->placeholder('Ungraded')
                    ->formatStateUsing(fn (AssignmentSubmission $record): string => $record->graded_at === null
                        ? '—'
                        : ((float) $record->marks_awarded).' / '.((float) $record->assignment->max_marks)),
            ])
            ->filters([
                TernaryFilter::make('graded')
                    ->label('Graded')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('graded_at'),
                        false: fn (Builder $query) => $query->whereNull('graded_at'),
                    ),
            ])
            ->recordActions([
                Action::make('grade')
                    ->label('Grade')
                    ->color('success')
                    ->visible(fn (AssignmentSubmission $record): bool => $record->graded_at === null)
                    ->schema(fn (AssignmentSubmission $record): array => blank($record->assignment->rubric)
                        ? [
                            TextInput::make('marks_awarded')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue((float) $record->assignment->max_marks)
                                ->required(),
                            Textarea::make('feedback')->rows(3),
                        ]
                        : [
                            ...collect($record->assignment->rubric)
                                ->map(fn (array $criterion) => TextInput::make("rubric_scores.{$criterion['id']}")
                                    ->label($criterion['criterion'])
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue((float) $criterion['max_points'])
                                    ->required())
                                ->all(),
                            Textarea::make('feedback')->rows(3),
                        ])
                    ->action(function (AssignmentSubmission $record, array $data): void {
                        app(AssignmentGradingService::class)->grade(
                            $record,
                            auth()->user(),
                            isset($data['marks_awarded']) ? (float) $data['marks_awarded'] : null,
                            $data['feedback'] ?? null,
                            isset($data['rubric_scores']) ? array_map('floatval', $data['rubric_scores']) : null,
                        );
                    }),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['assignment', 'user']));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssignmentSubmissions::route('/'),
        ];
    }
}
