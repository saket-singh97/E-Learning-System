<?php

namespace App\Domains\Growth\Filament\Resources\CourseInterestSignups;

use App\Domains\Growth\Filament\Resources\CourseInterestSignups\Pages\ListCourseInterestSignups;
use App\Domains\Growth\Models\CourseInterestSignup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Browse-only — every row comes from the public interest-capture form,
 * never a manual admin entry, same shape `FeePaymentResource` already
 * established for a similarly webhook/form-only-sourced model.
 */
class CourseInterestSignupResource extends Resource
{
    protected static ?string $model = CourseInterestSignup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $navigationLabel = 'Course interest leads';

    protected static ?string $recordTitleAttribute = 'email';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('course.title')->label('Course')->searchable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('phone')->placeholder('—'),
                TextColumn::make('notified_at')->dateTime()->placeholder('Not notified'),
                TextColumn::make('created_at')->label('Signed up')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('course_id')
                    ->label('Course')
                    ->relationship('course', 'title'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCourseInterestSignups::route('/'),
        ];
    }
}
