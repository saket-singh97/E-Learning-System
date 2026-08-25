<?php

namespace App\Domains\Assessments\Filament\Resources\TestSeries;

use App\Domains\Assessments\Filament\Resources\TestSeries\Pages\CreateTestSeries;
use App\Domains\Assessments\Filament\Resources\TestSeries\Pages\EditTestSeries;
use App\Domains\Assessments\Filament\Resources\TestSeries\Pages\ListTestSeries;
use App\Domains\Assessments\Models\TestSeries as TestSeriesModel;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * S16 B12 (I10 pt.1) — institute-authored bundle CRUD, same posture
 * `ProgramResource` already established: lives on the Admin panel
 * (institute_admin), registered via an explicit `->resources()` entry on
 * `AdminPanelProvider` rather than directory discovery — the Assessments
 * resource directory is otherwise Teach-panel-only
 * (Question/Quiz/QuizAnswer/FlaggedQuizAttempt).
 */
class TestSeriesResource extends Resource
{
    protected static ?string $model = TestSeriesModel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->required()
                ->maxLength(255),
            DateTimePicker::make('starts_at')
                ->native(false)
                ->helperText('Optional — leave blank for no fixed start.'),
            DateTimePicker::make('ends_at')
                ->native(false)
                ->helperText('Optional — leave blank for no fixed end.')
                ->after('starts_at'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('quizzes_count')->counts('quizzes')->label('Quizzes'),
                TextColumn::make('starts_at')->dateTime()->placeholder('—'),
                TextColumn::make('ends_at')->dateTime()->placeholder('—'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTestSeries::route('/'),
            'create' => CreateTestSeries::route('/create'),
            'edit' => EditTestSeries::route('/{record}/edit'),
        ];
    }
}
