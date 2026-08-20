<?php

namespace App\Domains\Content\Filament\Resources\Programs;

use App\Domains\Content\Filament\Resources\Programs\Pages\CreateProgram;
use App\Domains\Content\Filament\Resources\Programs\Pages\EditProgram;
use App\Domains\Content\Filament\Resources\Programs\Pages\ListPrograms;
use App\Domains\Content\Models\Program;
use App\Support\Tenancy\CurrentTenant;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

/**
 * Institute-authored bundle CRUD (design doc §3.1/decision 3) — lives on the
 * Admin panel (institute_admin), not Super: a Program bundles a tenant's
 * own Batches, same tenant-scoped-resource posture every other Content
 * Filament resource takes (CategoryResource/TagResource, B0). Registered on
 * AdminPanelProvider via an explicit ->resources() entry, not directory
 * discovery of Domains/Content/Filament/Resources — that directory also
 * holds CourseResource et al., deliberately Teach-panel-only.
 */
class ProgramResource extends Resource
{
    protected static ?string $model = Program::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedGiftTop;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (string $state, callable $set) => $set('slug', Str::slug($state))),
            TextInput::make('slug')
                ->required()
                ->maxLength(255)
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule): Unique => $rule
                        ->where('tenant_id', app(CurrentTenant::class)->id())
                        ->whereNull('deleted_at'),
                ),
            Textarea::make('description')
                ->maxLength(65535),
            TextInput::make('price')
                ->label('Bundle price (INR)')
                ->numeric()
                ->minValue(0)
                ->required(),
            Select::make('status')
                ->options(array_combine(Program::STATUSES, Program::STATUSES))
                ->required()
                ->default('draft')
                ->native(false),
            // Only published batches — selling access to a draft/archived
            // batch has nothing real to enrol the buyer into.
            Select::make('batches')
                ->relationship('batches', 'title', fn (Builder $query) => $query->where('status', 'published'))
                ->multiple()
                ->searchable()
                ->native(false)
                ->required()
                ->helperText('Buying this program will enrol the learner into every batch selected here.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('slug')->searchable(),
                TextColumn::make('price')->money('INR'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'published' ? 'success' : 'warning'),
                TextColumn::make('batches_count')->counts('batches')->label('Batches'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_combine(Program::STATUSES, Program::STATUSES)),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                ForceDeleteAction::make(),
                RestoreAction::make(),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrograms::route('/'),
            'create' => CreateProgram::route('/create'),
            'edit' => EditProgram::route('/{record}/edit'),
        ];
    }
}
