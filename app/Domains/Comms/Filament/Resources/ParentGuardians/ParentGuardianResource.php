<?php

namespace App\Domains\Comms\Filament\Resources\ParentGuardians;

use App\Domains\Comms\Filament\Resources\ParentGuardians\Pages\CreateParentGuardian;
use App\Domains\Comms\Filament\Resources\ParentGuardians\Pages\EditParentGuardian;
use App\Domains\Comms\Filament\Resources\ParentGuardians\Pages\ListParentGuardians;
use App\Domains\Comms\Models\ParentGuardianLink;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Backed by User, not a dedicated model — a "parent" is a User row with the
 * parent role, same as students are User rows the Enrolment relation
 * manager filters by role rather than a distinct model. Deliberately not
 * named UserResource (CLAUDE.md's {PluralModelName} convention would want
 * that): this resource only ever sees parent-role users, and "parent" says
 * that; a generic name would invite someone to widen its query() someday.
 */
class ParentGuardianResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Parents';

    protected static ?string $modelLabel = 'parent';

    protected static ?string $pluralModelLabel = 'parents';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->role('parent')
            ->when(
                app(CurrentTenant::class)->isBound(),
                fn (Builder $query) => $query->where('tenant_id', app(CurrentTenant::class)->id()),
            );
    }

    /**
     * The `student_user_id` Select's `options()` closure below is
     * tenant-scoped, and Filament validates a submitted value against
     * that same closure's result server-side — confirmed with a test
     * that submits a different tenant's student id and asserts a form
     * validation error, not silent acceptance (S8 B8 review: an initial
     * review pass assumed Filament's Select doesn't validate submitted
     * values against `options()` and this resource needed its own
     * server-side re-check; that assumption was wrong for a dynamic
     * `options()` closure like this one, verified by a failing test
     * before trusting it, not by memory of how Filament usually behaves).
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->email()
                ->required()
                ->unique(User::class, 'email', ignoreRecord: true),
            TextInput::make('phone')
                ->label('Phone (E.164, e.g. +919876543210)')
                ->tel()
                ->required(),
            TextInput::make('password')
                ->password()
                ->required()
                ->minLength(8)
                ->hiddenOn('edit'),
            Repeater::make('links')
                ->label('Linked students')
                ->schema([
                    Select::make('student_user_id')
                        ->label('Student')
                        ->options(fn () => User::role('student')
                            ->where('tenant_id', app(CurrentTenant::class)->id())
                            ->pluck('name', 'id'))
                        ->searchable()
                        ->required(),
                    TextInput::make('relationship')
                        ->label('Relationship')
                        ->placeholder('e.g. father, mother, guardian'),
                ])
                ->minItems(1)
                ->required()
                ->addActionLabel('Add another student'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('phone'),
                TextColumn::make('linked_students_count')
                    ->label('Linked students')
                    ->state(fn (User $record): int => ParentGuardianLink::where('parent_user_id', $record->id)->count()),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListParentGuardians::route('/'),
            'create' => CreateParentGuardian::route('/create'),
            'edit' => EditParentGuardian::route('/{record}/edit'),
        ];
    }
}
