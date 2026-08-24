<?php

namespace App\Domains\Assessments\Filament\Resources\FlaggedQuizAttempts;

use App\Domains\Assessments\Filament\Resources\FlaggedQuizAttempts\Pages\ListFlaggedQuizAttempts;
use App\Domains\Assessments\Models\QuizAnswer;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Assessments\Models\QuizProctoringEvent;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * S16 B11 (I9) — browse-only, same shape `QuizAnswerResource`/
 * `MessageReportResource` already established: no create/edit pages, every
 * row here comes from `QuizRunner::logProctoringEvent()`'s own
 * violation-threshold trip, never a manual entry. "Surfacing the event log
 * alongside the responses" (plan's own B11 note) is one modal action
 * rather than a second nested resource — this screen's whole job is
 * letting an instructor see *why* an attempt got auto-submitted.
 */
class FlaggedQuizAttemptResource extends Resource
{
    protected static ?string $model = QuizAttempt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Flagged attempts';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('flagged_for_review', true);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('quiz.title')->label('Quiz'),
                TextColumn::make('user.name')->label('Student'),
                TextColumn::make('status')->badge(),
                TextColumn::make('violation_count')
                    ->label('Violations')
                    ->state(fn (QuizAttempt $record): int => QuizProctoringEvent::where('quiz_attempt_id', $record->id)->count()),
                TextColumn::make('submitted_at')->dateTime()->sortable(),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->recordActions([
                Action::make('viewDetails')
                    ->label('View details')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema(fn (QuizAttempt $record): array => [
                        Placeholder::make('answers')
                            ->label('Responses')
                            ->content(fn (): string => QuizAnswer::where('quiz_attempt_id', $record->id)
                                ->with('question')
                                ->get()
                                ->map(fn (QuizAnswer $answer): string => ($answer->question?->stem ?? '?').': '.
                                    (is_array($answer->response) ? implode(', ', $answer->response) : ($answer->response ?? '(no answer)')))
                                ->implode("\n") ?: 'No answers recorded.'),
                        Placeholder::make('events')
                            ->label('Proctoring events')
                            ->content(fn (): string => QuizProctoringEvent::where('quiz_attempt_id', $record->id)
                                ->orderBy('occurred_at')
                                ->get()
                                ->map(fn (QuizProctoringEvent $event): string => "{$event->event_type} at {$event->occurred_at->format('H:i:s')}")
                                ->implode("\n") ?: 'No events recorded.'),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFlaggedQuizAttempts::route('/'),
        ];
    }
}
