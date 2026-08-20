<?php

namespace App\Domains\Comms\Filament\Resources\MessageReports;

use App\Domains\Comms\Filament\Resources\MessageReports\Pages\ListMessageReports;
use App\Domains\Comms\Models\MessageReport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Browse-only, same shape FeePaymentResource already established (S12
 * B13): every real row here comes from a student's own report action
 * (DirectMessages::reportMessage()), never a manual admin entry, so no
 * create/edit pages. A reported message doesn't auto-hide — an admin
 * actions or dismisses it here (design doc decision 10, "flag, don't
 * auto-act").
 */
class MessageReportResource extends Resource
{
    protected static ?string $model = MessageReport::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?string $navigationLabel = 'Message reports';

    protected static ?string $recordTitleAttribute = 'reason';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reporter.name')->label('Reported by')->searchable(),
                TextColumn::make('message.sender.name')->label('Message from'),
                TextColumn::make('message.body')->label('Message')->limit(80),
                TextColumn::make('reason')->limit(80),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'actioned' => 'danger',
                        'dismissed' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(array_combine(MessageReport::STATUSES, MessageReport::STATUSES)),
            ])
            ->recordActions([
                Action::make('markActioned')
                    ->label('Mark actioned')
                    ->requiresConfirmation()
                    ->visible(fn (MessageReport $record): bool => $record->status === 'open')
                    ->action(fn (MessageReport $record) => $record->update(['status' => 'actioned'])),
                Action::make('dismiss')
                    ->visible(fn (MessageReport $record): bool => $record->status === 'open')
                    ->action(fn (MessageReport $record) => $record->update(['status' => 'dismissed'])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMessageReports::route('/'),
        ];
    }
}
