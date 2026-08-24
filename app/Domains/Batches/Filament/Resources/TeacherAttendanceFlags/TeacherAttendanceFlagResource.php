<?php

namespace App\Domains\Batches\Filament\Resources\TeacherAttendanceFlags;

use App\Domains\Batches\Filament\Resources\TeacherAttendanceFlags\Pages\ListTeacherAttendanceFlags;
use App\Domains\Batches\Models\TeacherAttendanceFlag;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * S16 B6 (H13 pt.2) — browse-only, same shape `MessageReportResource`
 * already established: every row comes from `TeacherAttendanceSweep`
 * (B5/B6), never a manual admin entry, so no create/edit pages. "Resolve"
 * closes the flag and, optionally, reassigns the session's batch
 * `instructor_id` in the same action — no automatic substitute matching
 * (design doc §4 decision 10), the admin picks a real instructor by hand
 * or just dismisses the flag.
 */
class TeacherAttendanceFlagResource extends Resource
{
    protected static ?string $model = TeacherAttendanceFlag::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserMinus;

    protected static ?string $navigationLabel = 'Teacher Attendance Flags';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('batchSession.batch.title')->label('Batch'),
                TextColumn::make('instructor.name')->label('Flagged instructor'),
                TextColumn::make('flagged_at')->dateTime(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'open' ? 'warning' : 'success'),
            ])
            ->defaultSort('flagged_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(array_combine(TeacherAttendanceFlag::STATUSES, TeacherAttendanceFlag::STATUSES)),
            ])
            ->recordActions([
                Action::make('resolve')
                    ->label('Resolve')
                    ->visible(fn (TeacherAttendanceFlag $record): bool => $record->status === 'open')
                    ->schema([
                        Select::make('reassign_instructor_id')
                            ->label('Reassign batch instructor (optional)')
                            ->helperText('Leave blank to just close the flag with no reassignment.')
                            ->options(fn (): array => User::role('instructor')
                                ->where('tenant_id', app(CurrentTenant::class)->id())
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->nullable(),
                    ])
                    ->action(function (TeacherAttendanceFlag $record, array $data): void {
                        $record->update([
                            'status' => 'resolved',
                            'resolved_by_user_id' => auth()->id(),
                        ]);

                        if (filled($data['reassign_instructor_id'] ?? null)) {
                            $record->batchSession?->batch?->update([
                                'instructor_id' => $data['reassign_instructor_id'],
                            ]);
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeacherAttendanceFlags::route('/'),
        ];
    }
}
