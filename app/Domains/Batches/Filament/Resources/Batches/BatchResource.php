<?php

namespace App\Domains\Batches\Filament\Resources\Batches;

use App\Domains\Batches\Filament\Resources\Batches\Pages\CreateBatch;
use App\Domains\Batches\Filament\Resources\Batches\Pages\EditBatch;
use App\Domains\Batches\Filament\Resources\Batches\Pages\ListBatches;
use App\Domains\Batches\Filament\Resources\Batches\RelationManagers\BatchSessionsRelationManager;
use App\Domains\Batches\Filament\Resources\Batches\RelationManagers\EnrolmentsRelationManager;
use App\Domains\Batches\Filament\Resources\Batches\RelationManagers\FeedbackRelationManager;
use App\Domains\Batches\Models\Batch;
use App\Domains\Content\Models\Course;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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

class BatchResource extends Resource
{
    protected static ?string $model = Batch::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $recordTitleAttribute = 'title';

    /**
     * Shared between the Admin and Teach panels (S15 B2) — an
     * institute_admin still sees every tenant batch; an instructor sees
     * only batches assigned to them (`instructor_id`), same idiom
     * `ParentGuardianResource::getEloquentQuery()` already established for
     * a different "narrow this shared resource's own query" case. Scoping
     * unconditionally here (no panel check) would have hidden every batch
     * from institute_admin, since admins are essentially never a batch's
     * own `instructor_id`.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->when(
                Filament::getCurrentPanel()?->getId() === 'teach',
                fn (Builder $query) => $query->where('instructor_id', auth()->id()),
            );
    }

    /**
     * Instructors reach their own assigned batches to schedule sessions
     * and decide enrolments (via the two relation managers below), not to
     * create new batches or delete/restore existing ones — those stay
     * institute_admin actions, same "instructor sits below institute_admin"
     * posture RoleHierarchy already establishes.
     */
    public static function canCreate(): bool
    {
        return Filament::getCurrentPanel()?->getId() !== 'teach';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('course_id')
                ->label('Course')
                ->options(fn () => Course::where('status', 'published')->pluck('title', 'id'))
                ->searchable()
                ->required(),
            // B21 review-pass fix: instructor_id (B0) had no reachable
            // Admin-panel field anywhere — the only production path that
            // ever wrote it was InstructorInvitationService::accept(),
            // so an admin assigning/reassigning an already-active
            // instructor (not a fresh invitation) had no way to do it,
            // silently locking that instructor out of everything
            // EnrolmentApprovalPolicy::canDecide() and
            // TeacherConflictDetector gate on. Admin-only, same panel
            // idiom canCreate()/getEloquentQuery() above already use —
            // an instructor editing their own batch on the Teach panel
            // shouldn't be able to reassign it away from themselves.
            Select::make('instructor_id')
                ->label('Instructor')
                ->options(fn (): array => User::role('instructor')
                    ->where('tenant_id', app(CurrentTenant::class)->id())
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->nullable()
                ->visible(fn (): bool => Filament::getCurrentPanel()?->getId() !== 'teach'),
            TextInput::make('title')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (string $state, callable $set) => $set('slug', Str::slug($state))),
            TextInput::make('slug')
                ->required()
                ->maxLength(255)
                // batches has a partial unique index on (tenant_id, slug)
                // WHERE deleted_at IS NULL, same pattern as courses — mirror
                // both the tenant scope and the soft-delete exclusion here.
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule): Unique => $rule
                        ->where('tenant_id', app(CurrentTenant::class)->id())
                        ->whereNull('deleted_at'),
                ),
            Textarea::make('description')
                ->maxLength(65535),
            DatePicker::make('starts_on')
                ->required()
                ->native(false)
                ->before('ends_on'),
            DatePicker::make('ends_on')
                ->required()
                ->native(false)
                ->after('starts_on'),
            TextInput::make('seat_limit')
                ->numeric()
                ->minValue(1)
                ->nullable()
                ->helperText('Leave blank for unlimited seats.'),
            Toggle::make('is_paid')
                ->live()
                ->default(false),
            TextInput::make('price')
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->required(fn (Get $get): bool => (bool) $get('is_paid'))
                ->visible(fn (Get $get): bool => (bool) $get('is_paid')),
            Repeater::make('instalment_plan')
                ->label('Instalment plan (S7 B4)')
                ->helperText('Split the price into instalments — percentages must sum to exactly 100. Leave empty to charge the full price in one invoice at enrolment.')
                ->schema([
                    TextInput::make('percent')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(100)
                        ->suffix('%')
                        ->required(),
                    TextInput::make('days_after_enrolment')
                        ->label('Due (days after enrolment)')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->required(),
                ])
                ->columns(2)
                ->visible(fn (Get $get): bool => (bool) $get('is_paid'))
                // No built-in Filament rule sums a repeater's own
                // sub-values, so a custom one does it — first in this
                // codebase's Filament forms. ->rule() itself is evaluated
                // through Filament's own dependency-injecting evaluate()
                // (the same mechanism ->visible()/->required() use), not
                // handed straight to Laravel's validator — so the outer
                // closure here takes no attribute/value/fail params of its
                // own; it *returns* the actual Laravel in-line closure
                // rule ((string $attribute, $value, Closure $fail)), which
                // is what Laravel's validator then calls. Passing
                // (string $attribute, $value, Closure $fail) directly as
                // the outer closure fails at evaluation time — Filament
                // tries to dependency-inject $attribute and finds nothing
                // by that name (confirmed by running it, not guessed).
                ->rule(fn (): \Closure => function (string $attribute, $value, \Closure $fail): void {
                    if (empty($value)) {
                        return;
                    }

                    $sum = collect($value)->sum(fn (array $line): float => (float) ($line['percent'] ?? 0));

                    if (round($sum, 2) !== 100.0) {
                        $fail("Instalment percentages must sum to 100 (currently {$sum}).");
                    }
                }),
            Select::make('self_enrol_mode')
                ->options(array_combine(Batch::SELF_ENROL_MODES, Batch::SELF_ENROL_MODES))
                ->required()
                ->default('manual_only')
                ->native(false),
            Select::make('status')
                ->options(array_combine(Batch::STATUSES, Batch::STATUSES))
                ->required()
                ->default('draft')
                ->native(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('course.title')->label('Course')->searchable(),
                TextColumn::make('starts_on')->date(),
                TextColumn::make('ends_on')->date(),
                TextColumn::make('seat_limit')->label('Seats')->placeholder('Unlimited'),
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
                SelectFilter::make('status')->options(array_combine(Batch::STATUSES, Batch::STATUSES)),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                ...(Filament::getCurrentPanel()?->getId() === 'teach' ? [] : [
                    DeleteAction::make(),
                    ForceDeleteAction::make(),
                    RestoreAction::make(),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]));
    }

    public static function getRelations(): array
    {
        return [
            BatchSessionsRelationManager::class,
            EnrolmentsRelationManager::class,
            FeedbackRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBatches::route('/'),
            'create' => CreateBatch::route('/create'),
            'edit' => EditBatch::route('/{record}/edit'),
        ];
    }
}
