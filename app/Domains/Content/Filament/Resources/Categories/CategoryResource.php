<?php

namespace App\Domains\Content\Filament\Resources\Categories;

use App\Domains\Content\Filament\Resources\Categories\Pages\CreateCategory;
use App\Domains\Content\Filament\Resources\Categories\Pages\EditCategory;
use App\Domains\Content\Filament\Resources\Categories\Pages\ListCategories;
use App\Domains\Content\Models\Category;
use App\Support\Tenancy\CurrentTenant;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (string $state, callable $set) => $set('slug', Str::slug($state))),
            TextInput::make('slug')
                ->required()
                ->maxLength(255)
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('tenant_id', app(CurrentTenant::class)->id()),
                ),
            // Top-level only if left blank — no cycle guard beyond
            // excluding self, one level of nesting is all C7 asks for
            // (design doc decision 1).
            Select::make('parent_id')
                ->label('Parent category')
                ->options(fn (?Category $record) => Category::query()
                    ->when($record, fn ($query) => $query->whereKeyNot($record->id))
                    ->orderBy('name')
                    ->pluck('name', 'id'))
                ->searchable()
                ->native(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('slug')->searchable(),
                TextColumn::make('parent.name')->label('Parent')->placeholder('—'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                // A category still in use as a parent or attached to a
                // course must not be deletable outright — same
                // guard-delete posture PlanResource already established.
                DeleteAction::make()
                    ->visible(fn (Category $record): bool => $record->children()->doesntExist() && $record->courses()->doesntExist()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }
}
