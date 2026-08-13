<?php

namespace App\Domains\Assessments\Filament\Resources\QuizAnswers;

use App\Domains\Assessments\Filament\Resources\QuizAnswers\Pages\ListQuizAnswers;
use App\Domains\Assessments\Models\QuizAnswer;
use App\Domains\Assessments\Services\QuizAttemptService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * I5's "short-answer review queue" — read-only otherwise, so no create/edit
 * pages exist; the one action this screen needs is reviewing a pending row.
 * QuizAnswer rows now also come from QuizAttemptService::saveAnswer() (S9
 * B7's autosave, status 'draft') while an attempt is still in progress —
 * excluded from this queue's own query below, so an in-progress student's
 * autosaved-but-ungraded answer never shows up here mid-attempt.
 */
class QuizAnswerResource extends Resource
{
    protected static ?string $model = QuizAnswer::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $navigationLabel = 'Review queue';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('attempt.quiz.title')->label('Quiz'),
                TextColumn::make('attempt.user.name')->label('Student'),
                TextColumn::make('question.stem')->label('Question')->limit(60),
                TextColumn::make('response')->label('Response')->placeholder('(no answer)'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'reviewed' => 'success',
                        'pending_review' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('score'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(function (): array {
                    $reviewableStatuses = array_values(array_diff(QuizAnswer::STATUSES, ['draft']));

                    return array_combine($reviewableStatuses, $reviewableStatuses);
                }),
            ])
            ->recordActions([
                Action::make('review')
                    ->label('Review')
                    ->color('success')
                    ->visible(fn (QuizAnswer $record): bool => $record->status === 'pending_review')
                    ->schema([
                        TextInput::make('score')
                            ->label('Score (0-1)')
                            ->helperText('Partial credit for this one question — one point maximum.')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(1)
                            ->step(0.1)
                            ->required(),
                    ])
                    ->action(fn (QuizAnswer $record, array $data) => app(QuizAttemptService::class)->reviewAnswer(
                        $record,
                        (float) $data['score'],
                        auth()->user(),
                    )),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->where('status', '!=', 'draft')
                ->with(['attempt.quiz', 'attempt.user', 'question']));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuizAnswers::route('/'),
        ];
    }
}
