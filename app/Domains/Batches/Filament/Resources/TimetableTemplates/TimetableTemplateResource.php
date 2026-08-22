<?php

namespace App\Domains\Batches\Filament\Resources\TimetableTemplates;

use App\Domains\Batches\Filament\Resources\TimetableTemplates\Pages\CreateTimetableTemplate;
use App\Domains\Batches\Filament\Resources\TimetableTemplates\Pages\EditTimetableTemplate;
use App\Domains\Batches\Filament\Resources\TimetableTemplates\Pages\ListTimetableTemplates;
use App\Domains\Batches\Models\TimetableTemplate;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Admin-panel only (F7 pt.1) — directory-wide discovery picks this up for
 * Admin; TeachPanelProvider lists Batches resources explicitly (S15 B2)
 * and doesn't name this one, so it stays out of Teach.
 */
class TimetableTemplateResource extends Resource
{
    protected static ?string $model = TimetableTemplate::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->required()
                ->maxLength(255),
            Select::make('day_of_week')
                ->options(TimetableTemplate::DAYS_OF_WEEK)
                ->required()
                ->native(false),
            TimePicker::make('starts_at')
                ->required()
                ->seconds(false),
            TextInput::make('duration_minutes')
                ->label('Duration (minutes)')
                ->numeric()
                ->minValue(1)
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('day_of_week')
                    ->formatStateUsing(fn (int $state): string => TimetableTemplate::DAYS_OF_WEEK[$state]),
                TextColumn::make('starts_at')->time(),
                TextColumn::make('duration_minutes')->label('Duration (min)'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTimetableTemplates::route('/'),
            'create' => CreateTimetableTemplate::route('/create'),
            'edit' => EditTimetableTemplate::route('/{record}/edit'),
        ];
    }
}
