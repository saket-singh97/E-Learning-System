<?php

namespace App\Domains\Content\Filament\Resources\Courses;

use App\Domains\Content\Filament\Resources\Courses\Pages\CreateCourse;
use App\Domains\Content\Filament\Resources\Courses\Pages\EditCourse;
use App\Domains\Content\Filament\Resources\Courses\Pages\ListCourses;
use App\Domains\Content\Filament\Resources\Courses\RelationManagers\SectionsRelationManager;
use App\Domains\Content\Models\Course;
use App\Support\Tenancy\CurrentTenant;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
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

class CourseResource extends Resource
{
    protected static ?string $model = Course::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedRectangleStack;

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
                // courses has a partial unique index on (tenant_id, slug)
                // WHERE deleted_at IS NULL (see the migration that made the
                // slug reusable after a soft-delete) — the validation rule
                // has to mirror both the tenant scope and the soft-delete
                // exclusion, or a duplicate slug within the same tenant hits
                // an unhandled QueryException on submit instead of an inline
                // "already taken" message.
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule): Unique => $rule
                        ->where('tenant_id', app(CurrentTenant::class)->id())
                        ->whereNull('deleted_at'),
                ),
            Textarea::make('description')
                ->maxLength(65535),
            Select::make('status')
                ->options(array_combine(Course::STATUSES, Course::STATUSES))
                ->required()
                ->default('draft')
                ->native(false),
            // S10 B0's migration defaults ({"quiz":50,"assignment":50} /
            // 40.00) already apply to every existing course at the DB
            // level — these fields just let an admin override them, no
            // one-time "set your weights" migration wizard needed.
            TextInput::make('gradebook_weights.quiz')
                ->label('Quiz weight %')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->default(50)
                ->required(),
            TextInput::make('gradebook_weights.assignment')
                ->label('Assignment weight %')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->default(50)
                ->required()
                // Same "outer closure returns the real Laravel rule
                // closure" shape BatchResource's own instalment-percent
                // sum check established (S7 B4) — ->rule() runs through
                // Filament's dependency-injecting evaluate(), not handed
                // straight to Laravel's validator.
                ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                    $sum = (float) ($get('gradebook_weights.quiz') ?? 0) + (float) ($value ?? 0);

                    if (round($sum, 2) !== 100.0) {
                        $fail("Quiz and assignment weights must sum to 100 (currently {$sum}).");
                    }
                }),
            TextInput::make('passing_threshold')
                ->label('Passing threshold %')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->default(40)
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('slug')->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'published' => 'success',
                        'archived' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_combine(Course::STATUSES, Course::STATUSES)),
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

    public static function getRelations(): array
    {
        return [
            SectionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCourses::route('/'),
            'create' => CreateCourse::route('/create'),
            'edit' => EditCourse::route('/{record}/edit'),
        ];
    }
}
