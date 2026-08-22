<?php

namespace App\Domains\Batches\Filament\Resources\Batches\RelationManagers;

use App\Domains\Batches\Events\AttendanceRecorded;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Batches\Models\TimetableTemplate;
use App\Domains\Batches\Services\TeacherConflictDetector;
use App\Domains\Content\Models\AttendanceRecord;
use App\Domains\Content\Models\Lesson;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BatchSessionsRelationManager extends RelationManager
{
    protected static string $relationship = 'sessions';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->maxLength(255),
            // Design decision 3 — a stamp, not a live link: prefills
            // starts_at/duration_minutes from the template's own columns
            // (next calendar occurrence of its day_of_week), no FK stored
            // anywhere. Create-only — an edit's existing values shouldn't
            // silently get overwritten by picking this.
            Select::make('from_template')
                ->label('Prefill from template')
                ->options(fn (): array => TimetableTemplate::pluck('title', 'id')->all())
                ->live()
                ->dehydrated(false)
                ->hiddenOn('edit')
                ->afterStateUpdated(function (?string $state, callable $set): void {
                    $template = $state === null ? null : TimetableTemplate::find($state);

                    if ($template === null) {
                        return;
                    }

                    $time = Carbon::parse($template->starts_at);
                    $nextDate = Carbon::now()->next($template->day_of_week)->setTimeFrom($time);

                    $set('starts_at', $nextDate->format('Y-m-d H:i:s'));
                    $set('duration_minutes', $template->duration_minutes);
                }),
            DateTimePicker::make('starts_at')
                ->required()
                ->native(false)
                // Write-time refusal (design decision 4), same "a fail()
                // message reads better than a caught exception mid-submit"
                // idiom PrerequisiteGraph's own cycle check already
                // established for CourseResource/LessonResource's rules.
                ->rule(fn (Get $get, ?BatchSession $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                    $durationMinutes = (int) ($get('duration_minutes') ?? 0);

                    if ($value === null || $durationMinutes <= 0) {
                        return;
                    }

                    /** @var Batch $batch */
                    $batch = $this->getOwnerRecord();

                    if (app(TeacherConflictDetector::class)->wouldConflict($batch, $value, $durationMinutes, $record?->id)) {
                        $fail('This overlaps another session already scheduled for this batch\'s instructor.');
                    }
                }),
            TextInput::make('duration_minutes')
                ->label('Duration (minutes)')
                ->numeric()
                ->required()
                ->minValue(15)
                ->maxValue(480),
            Select::make('lesson_id')
                ->label('Live class topic')
                ->helperText('Only live_session lessons in this batch\'s course. Leave blank for a plain timetable entry.')
                ->options(fn (): array => Lesson::query()
                    ->where('type', 'live_session')
                    ->whereHas('section', fn ($query) => $query->where('course_id', $this->getOwnerRecord()->course_id))
                    ->pluck('title', 'id')
                    ->all())
                ->searchable()
                ->live(),
            Select::make('live_provider')
                ->label('Join method')
                ->options([
                    'external_link' => 'External link (Zoom/Meet/etc. — paste a URL)',
                    'zoom_video_sdk' => 'Zoom Video SDK (room created automatically)',
                    'zoom_meetings_oauth' => 'Zoom (real meeting via connected institute account)',
                    'google_meet' => 'Google Meet (room created automatically)',
                ])
                ->default('external_link')
                ->live()
                ->visible(fn (Get $get): bool => filled($get('lesson_id')))
                ->required(fn (Get $get): bool => filled($get('lesson_id'))),
            TextInput::make('provider_meeting_ref')
                ->label('Meeting URL')
                ->url()
                ->visible(fn (Get $get): bool => filled($get('lesson_id')) && $get('live_provider') === 'external_link')
                ->required(fn (Get $get): bool => filled($get('lesson_id')) && $get('live_provider') === 'external_link'),
        ]);
    }

    /**
     * A linked lesson_id is what makes this a live class occurrence, not a
     * plain timetable entry.
     */
    protected function scheduleLiveRoom(BatchSession $record, array $data): void
    {
        if (filled($data['lesson_id'] ?? null)) {
            $record->createLiveRoom();
        }
    }

    /**
     * The roster this session's attendance is taken against — every
     * actively enrolled student in the session's own batch (design doc
     * §3.1: "against a BatchSession's enrolled roster").
     *
     * @return Collection<int, int>
     */
    protected function enrolledUserIds(BatchSession $record): Collection
    {
        return Enrolment::where('batch_id', $record->batch_id)
            ->where('status', 'active')
            ->pluck('user_id');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->reorderable('position')
            ->defaultSort('position')
            ->columns([
                TextColumn::make('title')->placeholder('Untitled session'),
                TextColumn::make('starts_at')->dateTime(),
                TextColumn::make('duration_minutes')->label('Duration (min)'),
                TextColumn::make('lesson.title')->label('Live topic')->placeholder('—')->toggleable(),
            ])
            ->headerActions([
                // Same tie-break gap as SectionsRelationManager/LessonsRelation
                // Manager: reorderable() never assigns a position on create,
                // so unreordered sessions all sit at the column's DB default
                // of 0 and Batch::sessions()'s orderBy('position') breaks the
                // tie by arbitrary DB order instead of authoring order.
                CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['position'] = (int) BatchSession::where('batch_id', $this->getOwnerRecord()->id)
                            ->max('position') + 1;

                        return $data;
                    })
                    ->after(function (BatchSession $record, array $data): void {
                        $this->scheduleLiveRoom($record, $data);
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->after(function (BatchSession $record, array $data): void {
                        $this->scheduleLiveRoom($record, $data);
                    }),
                Action::make('markAttendance')
                    ->label('Mark attendance')
                    ->visible(fn (BatchSession $record): bool => $record->lesson_id !== null)
                    ->schema(fn (BatchSession $record): array => [
                        CheckboxList::make('present_user_ids')
                            ->label('Present')
                            ->options(fn (): array => User::whereIn('id', $this->enrolledUserIds($record))
                                ->pluck('name', 'id')
                                ->all())
                            // Default-all-present (H2): every enrolled student
                            // starts checked, teacher taps absentees off.
                            ->default(fn (): array => $this->enrolledUserIds($record)->all())
                            ->bulkToggleable()
                            ->columns(2),
                    ])
                    ->action(function (BatchSession $record, array $data): void {
                        $presentUserIds = array_map('intval', $data['present_user_ids'] ?? []);
                        $markedBy = auth()->id();

                        $this->enrolledUserIds($record)->each(function (int $userId) use ($record, $presentUserIds, $markedBy): void {
                            $status = in_array($userId, $presentUserIds, true) ? 'present' : 'absent';

                            AttendanceRecord::updateOrCreate(
                                [
                                    'attendable_type' => BatchSession::class,
                                    'attendable_id' => $record->id,
                                    'user_id' => $userId,
                                ],
                                [
                                    'status' => $status,
                                    'source' => 'manual',
                                    'marked_by' => $markedBy,
                                    'recorded_at' => now(),
                                ]
                            );

                            event(new AttendanceRecorded($userId, $record->tenant_id, $status));
                        });
                    }),
                DeleteAction::make(),
            ]);
    }
}
