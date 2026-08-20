<?php

namespace App\Domains\Content\Filament\Resources\CourseReviews;

use App\Domains\Content\Filament\Resources\CourseReviews\Pages\ListCourseReviews;
use App\Domains\Content\Models\CourseReview;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Browse-only, same shape FeePaymentResource/MessageReportResource already
 * established: every real row comes from a learner's own submit-review
 * flow, never a manual admin entry. Moderation is hide-not-delete and
 * reversible (design doc decision 11) — unlike MessageReportResource's own
 * one-way open->actioned/dismissed transitions, hide/show here go both
 * ways, so the row's own current status decides which single action shows.
 */
class CourseReviewResource extends Resource
{
    protected static ?string $model = CourseReview::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedStar;

    protected static ?string $navigationLabel = 'Course reviews';

    protected static ?string $recordTitleAttribute = 'body';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('course.title')->label('Course')->searchable(),
                TextColumn::make('user.name')->label('Reviewer')->searchable(),
                TextColumn::make('rating')->formatStateUsing(fn (int $state): string => "{$state}/5"),
                TextColumn::make('body')->limit(80)->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'published' ? 'success' : 'gray'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(array_combine(CourseReview::STATUSES, CourseReview::STATUSES)),
            ])
            ->recordActions([
                Action::make('hide')
                    ->requiresConfirmation()
                    ->visible(fn (CourseReview $record): bool => $record->status === 'published')
                    ->action(fn (CourseReview $record) => $record->update(['status' => 'hidden'])),
                Action::make('show')
                    ->visible(fn (CourseReview $record): bool => $record->status === 'hidden')
                    ->action(fn (CourseReview $record) => $record->update(['status' => 'published'])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCourseReviews::route('/'),
        ];
    }
}
