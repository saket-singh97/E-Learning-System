<?php

namespace App\Domains\Batches\Filament\Resources\Batches;

use App\Domains\Batches\Filament\Resources\Batches\Pages\CreateBatch;
use App\Domains\Batches\Filament\Resources\Batches\Pages\EditBatch;
use App\Domains\Batches\Filament\Resources\Batches\Pages\ListBatches;
use App\Domains\Batches\Filament\Resources\Batches\RelationManagers\BatchSessionsRelationManager;
use App\Domains\Batches\Filament\Resources\Batches\RelationManagers\EnrolmentsRelationManager;
use App\Domains\Batches\Filament\Resources\Batches\RelationManagers\FeedbackRelationManager;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchGeofence;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\TimetableTemplate;
use App\Domains\Batches\Services\AutoSchedulerService;
use App\Domains\Batches\Services\TeacherConflictDetector;
use App\Domains\Content\Models\Course;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
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
                    static::geofenceAction(),
                    static::autoScheduleAction(),
                    DeleteAction::make(),
                    ForceDeleteAction::make(),
                    RestoreAction::make(),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]));
    }

    /**
     * S16 B2 (H11 pt.1) — a small modal form on BatchResource, same
     * "extends the existing resource" shape FeedbackRelationManager (S15
     * B8) already took, rather than a standalone resource for a table
     * that's ever at most one row per batch. Admin-panel only, same
     * instructor/admin split canCreate()/getEloquentQuery() already draw.
     */
    protected static function geofenceAction(): Action
    {
        return Action::make('geofence')
            ->label(fn (Batch $record): string => $record->geofence ? 'Edit geofence' : 'Set geofence')
            ->icon(Heroicon::OutlinedMapPin)
            ->fillForm(fn (Batch $record): array => [
                'latitude' => $record->geofence?->latitude,
                'longitude' => $record->geofence?->longitude,
                'radius_meters' => $record->geofence?->radius_meters,
            ])
            ->schema([
                TextInput::make('latitude')
                    ->numeric()
                    ->minValue(-90)
                    ->maxValue(90)
                    ->required(),
                TextInput::make('longitude')
                    ->numeric()
                    ->minValue(-180)
                    ->maxValue(180)
                    ->required(),
                TextInput::make('radius_meters')
                    ->label('Radius (meters)')
                    ->numeric()
                    ->minValue(1)
                    ->required(),
            ])
            ->action(function (Batch $record, array $data): void {
                BatchGeofence::updateOrCreate(
                    ['batch_id' => $record->id],
                    [
                        'tenant_id' => $record->tenant_id,
                        'latitude' => $data['latitude'],
                        'longitude' => $data['longitude'],
                        'radius_meters' => $data['radius_meters'],
                    ],
                );
            });
    }

    /**
     * S24 B5 (U22 pt.2) — reviews `AutoSchedulerService::propose()`'s
     * in-memory candidates (S24 B4, never persisted by that service
     * itself) then writes the accepted ones through the *same*
     * position-auto-increment shape `BatchSessionsRelationManager`'s own
     * `CreateAction` already uses — no new persistence path, same
     * `BatchSession::create()` this whole domain already writes through.
     * A skipped/unaccepted candidate is simply never created. Admin-panel
     * only, same instructor/admin split every other action in this array
     * already draws.
     */
    protected static function autoScheduleAction(): Action
    {
        return Action::make('autoSchedule')
            ->label('Auto-schedule remaining sessions')
            ->visible(fn (): bool => TimetableTemplate::query()->exists())
            ->fillForm(fn (Batch $record): array => [
                'session_count' => 4,
                'candidates' => static::proposalCandidates($record, 4),
            ])
            ->schema([
                TextInput::make('session_count')
                    ->label('How many sessions to propose')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(20)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (?string $state, callable $set, Batch $record): void {
                        $set('candidates', static::proposalCandidates($record, (int) $state));
                    }),
                Repeater::make('candidates')
                    ->label('Proposed sessions — uncheck any you don\'t want')
                    ->schema([
                        Toggle::make('accept')->label('Accept')->default(true),
                        Placeholder::make('when_display')
                            ->label('When')
                            ->content(fn (callable $get): string => $get('when')),
                        Hidden::make('when'),
                        Hidden::make('starts_at'),
                        Hidden::make('duration_minutes'),
                    ])
                    ->columns(2)
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->default([]),
            ])
            ->action(function (Batch $record, array $data): void {
                foreach ($data['candidates'] ?? [] as $candidate) {
                    if (! ($candidate['accept'] ?? false)) {
                        continue;
                    }

                    // Candidates were computed conflict-free against the DB
                    // state at fillForm/afterStateUpdated time, but nothing
                    // stopped the instructor's real schedule from changing
                    // before this submit — a second admin accepting another
                    // proposal for the same instructor, or a manual session
                    // created via BatchSessionsRelationManager in between
                    // (S24 B13 second-pass review). Re-checked here, the
                    // same write-time gate that manual create already
                    // enforces, so a stale slot is skipped rather than
                    // silently double-booking the instructor.
                    if (app(TeacherConflictDetector::class)->wouldConflict($record, $candidate['starts_at'], $candidate['duration_minutes'])) {
                        continue;
                    }

                    $position = (int) BatchSession::where('batch_id', $record->id)->max('position') + 1;

                    BatchSession::create([
                        'batch_id' => $record->id,
                        'starts_at' => $candidate['starts_at'],
                        'duration_minutes' => $candidate['duration_minutes'],
                        'position' => $position,
                    ]);
                }
            });
    }

    /**
     * @return array<int, array{accept: bool, when: string, starts_at: string, duration_minutes: int}>
     */
    protected static function proposalCandidates(Batch $record, int $sessionCount): array
    {
        return app(AutoSchedulerService::class)->propose($record, max(1, $sessionCount))
            ->map(fn (array $proposal): array => [
                'accept' => true,
                'when' => TimetableTemplate::DAYS_OF_WEEK[$proposal['day_of_week']].' '.$proposal['starts_at']->format('Y-m-d H:i'),
                'starts_at' => $proposal['starts_at']->format('Y-m-d H:i:s'),
                'duration_minutes' => $proposal['duration_minutes'],
            ])
            ->all();
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
