<?php

namespace App\Domains\Growth\Filament\Resources\ScholarshipTests;

use App\Domains\Growth\Filament\Resources\ScholarshipTests\Pages\CreateScholarshipTest;
use App\Domains\Growth\Filament\Resources\ScholarshipTests\Pages\EditScholarshipTest;
use App\Domains\Growth\Filament\Resources\ScholarshipTests\Pages\ListScholarshipTests;
use App\Domains\Growth\Filament\Resources\ScholarshipTests\RelationManagers\RegistrationsRelationManager;
use App\Domains\Growth\Models\ScholarshipTest;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ScholarshipTestResource extends Resource
{
    protected static ?string $model = ScholarshipTest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $navigationLabel = 'Scholarship tests';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->required()
                ->maxLength(255),
            Select::make('course_id')
                ->relationship('course', 'title')
                ->searchable()
                ->native(false)
                ->helperText('Optional — leave blank for a tenant-wide test not tied to one course.'),
            Select::make('quiz_id')
                ->relationship('quiz', 'title')
                ->required()
                ->searchable()
                ->native(false),
            DateTimePicker::make('opens_at')
                ->required()
                ->native(false),
            DateTimePicker::make('closes_at')
                ->required()
                ->native(false)
                ->after('opens_at'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('course.title')->placeholder('—'),
                TextColumn::make('quiz.title'),
                TextColumn::make('opens_at')->dateTime()->sortable(),
                TextColumn::make('closes_at')->dateTime()->sortable(),
                TextColumn::make('registrations_count')->counts('registrations')->label('Registrations'),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RegistrationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScholarshipTests::route('/'),
            'create' => CreateScholarshipTest::route('/create'),
            'edit' => EditScholarshipTest::route('/{record}/edit'),
        ];
    }
}
