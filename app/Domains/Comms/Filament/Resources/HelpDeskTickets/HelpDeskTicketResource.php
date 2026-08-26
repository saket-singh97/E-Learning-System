<?php

namespace App\Domains\Comms\Filament\Resources\HelpDeskTickets;

use App\Domains\Comms\Filament\Resources\HelpDeskTickets\Pages\ListHelpDeskTickets;
use App\Domains\Comms\Models\HelpDeskTicket;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * S19 N16 pt.2 — browse-only, same shape `MessageReportResource`/
 * `TeacherAttendanceFlagResource` already established: every row comes
 * from a requester's own `HelpDeskTicketForm::raiseTicket()` (B7), never a
 * manual admin entry, so no create/edit pages. Same `Action->schema()->
 * action()` modal-form idiom `TeacherAttendanceFlagResource`'s own
 * "resolve" action already established, for both `assign` and `reply`.
 */
class HelpDeskTicketResource extends Resource
{
    protected static ?string $model = HelpDeskTicket::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static ?string $navigationLabel = 'Help Desk';

    protected static ?string $recordTitleAttribute = 'subject';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('subject')->searchable(),
                TextColumn::make('requester.name')->label('Requester'),
                TextColumn::make('priority')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'high' => 'danger',
                        'low' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'resolved', 'closed' => 'success',
                        'in_progress' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('assignee.name')->label('Assigned to')->placeholder('Unassigned'),
                TextColumn::make('sla_due_at')->dateTime(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(array_combine(HelpDeskTicket::STATUSES, HelpDeskTicket::STATUSES)),
                SelectFilter::make('priority')->options(array_combine(HelpDeskTicket::PRIORITIES, HelpDeskTicket::PRIORITIES)),
            ])
            ->recordActions([
                Action::make('assign')
                    ->schema([
                        Select::make('assigned_to_user_id')
                            ->label('Assign to')
                            ->options(fn (): array => User::role(['institute_admin', 'instructor'])
                                ->where('tenant_id', app(CurrentTenant::class)->id())
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->required(),
                    ])
                    ->action(fn (HelpDeskTicket $record, array $data) => $record->update([
                        'assigned_to_user_id' => $data['assigned_to_user_id'],
                    ])),
                Action::make('reply')
                    ->schema([
                        Textarea::make('body')
                            ->required()
                            ->maxLength(5000),
                        Checkbox::make('is_internal_note')
                            ->label('Internal note (staff only — the requester never sees this)'),
                    ])
                    ->action(function (HelpDeskTicket $record, array $data): void {
                        $record->replies()->create([
                            'author_id' => auth()->id(),
                            'body' => $data['body'],
                            'is_internal_note' => $data['is_internal_note'] ?? false,
                        ]);
                    }),
                Action::make('markInProgress')
                    ->label('Mark in progress')
                    ->visible(fn (HelpDeskTicket $record): bool => $record->status === 'open')
                    ->action(fn (HelpDeskTicket $record) => $record->update(['status' => 'in_progress'])),
                Action::make('resolve')
                    ->visible(fn (HelpDeskTicket $record): bool => in_array($record->status, ['open', 'in_progress'], true))
                    ->action(fn (HelpDeskTicket $record) => $record->update(['status' => 'resolved', 'resolved_at' => now()])),
                Action::make('close')
                    ->visible(fn (HelpDeskTicket $record): bool => $record->status === 'resolved')
                    ->action(fn (HelpDeskTicket $record) => $record->update(['status' => 'closed'])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHelpDeskTickets::route('/'),
        ];
    }
}
